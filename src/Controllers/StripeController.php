<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Services\StripePaymentGateway;
use Acelle\Library\Facades\Billing;
use Acelle\Model\Setting;
use Acelle\Model\Invoice;
use Acelle\Library\TransactionVerificationResult;
use Acelle\Model\Transaction;
use Acelle\Library\AutoBillingData;


class StripeController extends Controller
{
    public function settings(Request $request)
    {
        $gateway = Billing::getGateway('stripe');

        if ($request->isMethod('post')) {
            // make validator
            $validator = \Validator::make($request->all(), [
                'secret_key' => 'required',
                'publishable_key' => 'required',
            ]);

            // test service
            $validator->after(function ($validator) use ($gateway, $request) {
                try {
                    $stripe = new StripePaymentGateway($request->publishable_key, $request->secret_key);
                    $stripe->test();
                } catch(\Exception $e) {
                    $validator->errors()->add('field', 'Can not connect to ' . $gateway->getName() . '. Error: ' . $e->getMessage());
                }
            });

            // redirect if fails
            if ($validator->fails()) {
                return response()->view('cashier::stripe.settings', [
                    'gateway' => $gateway,
                    'errors' => $validator->errors(),
                ], 400);
            }

            // save settings
            Setting::set('cashier.stripe.secret_key', $request->secret_key);
            Setting::set('cashier.stripe.publishable_key', $request->publishable_key);

            // enable if not validate
            if ($request->enable_gateway) {
                Billing::enablePaymentGateway($gateway->getType());
            }

            $request->session()->flash('alert-success', trans('cashier::messages.gateway.updated'));
            return redirect()->action('Admin\PaymentController@index');
        }

        return view('cashier::stripe.settings', [
            'gateway' => $gateway,
        ]);
    }

    public function getCheckoutUrl($invoice)
    {
        return action("\Acelle\Cashier\Controllers\StripeController@checkout", [
            'invoice_uid' => $invoice->uid,
        ]);
    }

    /**
     * Get current payment service.
     *
     * @return \Illuminate\Http\Response
     **/
    public function getPaymentService()
    {
        return Billing::getGateway('stripe');
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
        $customer = $request->user()->customer;
        $service = $this->getPaymentService();
        $invoice = Invoice::findByUid($invoice_uid);

        // exceptions
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        // free plan. No charge
        if ($invoice->total() == 0) {
            $invoice->checkout($service, function($invoice) {
                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_DONE);
            });

            return redirect()->action('AccountSubscriptionController@index');
        }

        // Customer has no card
        if (!$service->hasCard($customer)) {
            // connect again
            return redirect()->away(
                $service->getAutoBillingDataUpdateUrl(
                    $service->getCheckoutUrl($invoice)
                )
            );
        }

        if ($request->isMethod('post')) {
            $service->autoCharge($invoice);

            // return back
            return redirect()->action('AccountSubscriptionController@index');
        }

        return view('cashier::stripe.charging', [
            'invoice' => $invoice,
        ]);
    }

    /**
     * Connect to gateway.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function autoBillingDataUpdate(Request $request)
    {
        // Get current customer
        $service = Billing::getGateway('stripe');

        // get card
        $card = $service->getCardInformation($request->user()->customer);
        
        if ($request->isMethod('post')) {
            
            $stripeCustomer = $service->getStripeCustomer($request->user()->customer);

            if (!$request->use_current_card) {
                // update card
                $card = $service->billableUserUpdateCard($request->user()->customer, $request->all());
            } else {
                // set gateway
                $card = $service->getCardInformation($request->user()->customer);
            }

            // update auto billing data
            $autoBillingData = new AutoBillingData($service, [
                'source' => $card->id,
                'customer' => $stripeCustomer->id,
                'card_last4' => $card->last4,
            ]);
            $request->user()->customer->setAutoBillingData($autoBillingData);
            
            // return to billing page
            $request->session()->flash('alert-success', trans('cashier::messages.stripe.connected'));
            return redirect()->action('AccountSubscriptionController@index');
        }
        
        return view('cashier::stripe.autoBillingDataUpdate', [
            'service' => $service,
            'cardInfo' => $card,
            'return_url' => $request->return_url,
        ]);
    }
}
