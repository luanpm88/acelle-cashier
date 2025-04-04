<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Acelle\Cashier\Services\BraintreePaymentGateway;
use Acelle\Library\Facades\Billing;
use Acelle\Model\Setting;
use Acelle\Library\AutoBillingData;
use Acelle\Model\Invoice;
use Acelle\Library\TransactionResult;

class BraintreeController extends Controller
{
    public function settings(Request $request)
    {
        $gateway = Billing::getGateway('braintree');

        if ($request->isMethod('post')) {
            // make validator
            $validator = \Validator::make($request->all(), [
                'environment' => 'required',
                'merchant_id' => 'required',
                'public_key' => 'required',
                'private_key' => 'required',
            ]);

            // test service
            $validator->after(function ($validator) use ($gateway, $request) {
                try {
                    $braintree = new BraintreePaymentGateway($request->environment, $request->merchant_id, $request->public_key, $request->private_key);
                    $braintree->test();
                } catch(\Exception $e) {
                    $validator->errors()->add('field', 'Can not connect to ' . $gateway->getName() . '. Error: ' . $e->getMessage());
                }
            });

            // redirect if fails
            if ($validator->fails()) {
                return response()->view('cashier::braintree.settings', [
                    'gateway' => $gateway,
                    'errors' => $validator->errors(),
                ], 400);
            }

            // save settings
            Setting::set('cashier.braintree.environment', $request->environment);
            Setting::set('cashier.braintree.merchant_id', $request->merchant_id);
            Setting::set('cashier.braintree.public_key', $request->public_key);
            Setting::set('cashier.braintree.private_key', $request->private_key);

            // enable if not validate
            if ($request->enable_gateway) {
                Billing::enablePaymentGateway($gateway->getType());
            }

            $request->session()->flash('alert-success', trans('cashier::messages.gateway.updated'));
            return redirect()->action('Admin\PaymentController@index');
        }

        return view('cashier::braintree.settings', [
            'gateway' => $gateway,
        ]);
    }

    /**
     * Get current payment service.
     *
     * @return \Illuminate\Http\Response
     **/
    public function getPaymentService()
    {
        return Billing::getGateway('braintree');
    }

    /**
     * Subscription checkout page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
    **/
    public function checkout(Request $request, $invoice_uid)
    {
        $service = $this->getPaymentService();
        $invoice = Invoice::findByUid($invoice_uid);
        $card = $service->getCardInformation($invoice->billing_email);
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }

        // exceptions
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        // free plan. No charge
        if ($invoice->total() == 0) {
            $invoice->checkout($service, function($invoice) {
                return new TransactionResult(TransactionResult::RESULT_DONE);
            });

            return redirect()->away(Billing::getReturnUrl());
        }

        if ($request->isMethod('post')) {
            if (!$request->use_current_card) {
                // update card
                $service->updateCard($invoice->billing_email, $request->nonce);
            }

            // get card
            $card = $service->getCardInformation($invoice->billing_email);

            // handle null card
            if (is_null($card)) {
                return redirect()->action("\Acelle\Cashier\Controllers\BraintreeController@checkout", [
                    'invoice_uid' => $invoice->uid,
                ])->with('alert-warning', 'Unable to retrieve card information. Please try again!');
            }

            // update auto billing data
            $autoBillingData = new AutoBillingData($service, [
                'paymentMethodToken' => $card->token,
                'card_last4' => $card->last4,
                'card_type' => $card->cardType,
            ]);
            $invoice->customer->setAutoBillingData($autoBillingData);

            // card not save. Try again until success
            if (!$service->hasCard($invoice->billing_email, $invoice->customer->getAutoBillingData())) {
                return redirect()->action("\Acelle\Cashier\Controllers\BraintreeController@checkout", [
                    'invoice_uid' => $invoice->uid,
                ])->with('alert-warning', 'Something went wrong! Please try again!');
            }

            // auto charge
            $result = $service->autoCharge($invoice);

            // return back
            return redirect()->away(Billing::getReturnUrl());
        }

        return view('cashier::braintree.checkout', [
            'service' => $service,
            'clientToken' => $service->serviceGateway->clientToken()->generate(),
            'cardInfo' => $card,
            'invoice' => $invoice,
        ]);
    }
    
    public function autoBillingDataUpdate(Request $request)
    {
        return redirect()->away(Billing::getReturnUrl());;
    }
}
