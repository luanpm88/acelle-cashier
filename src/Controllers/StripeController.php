<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Acelle\Cashier\Services\StripePaymentGateway;
use Acelle\Library\Facades\Billing;
use Acelle\Model\Setting;
use Acelle\Model\Invoice;
use Acelle\Cashier\Library\TransactionVerificationResult;
use Acelle\Cashier\Library\AutoBillingData;


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
        $service = $this->getPaymentService();
        $invoice = Invoice::findByUid($invoice_uid);
        $customer = $invoice->customer;

        // exceptions
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        // free plan. No charge
        if ($invoice->total() == 0) {
            $invoice->checkout($service, function($invoice) {
                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_DONE);
            });

            return redirect()->away(Billing::getReturnUrl());;
        }

        if ($request->isMethod('post')) {
            // Use current card
            if ($request->current_card) {
                $invoice->checkout($service, function($invoice) use ($service, $customer) {
                    $service->updatePaymentMethod($customer, $invoice);

                    // charge invoice
                    $service->pay($invoice);

                    return new TransactionVerificationResult(TransactionVerificationResult::RESULT_DONE);
                });

                return redirect()->away(Billing::getReturnUrl());;

            // Use new card. User already paid before, just return done.
            } else {
                $stripeCustomer = $service->getStripeCustomer($customer);

                // update auto billing data
                $autoBillingData = new AutoBillingData($service, [
                    'payment_method_id' => $request->payment_method_id,
                    'customer_id' => $stripeCustomer->id,
                ]);
                $customer->setAutoBillingData($autoBillingData);

                // invoice checkout
                $invoice->checkout($service, function($invoice) {
                    return new TransactionVerificationResult(TransactionVerificationResult::RESULT_DONE);
                });
            }
        }

        return view('cashier::stripe.checkout', [
            'service' => $service,
            'invoice' => $invoice,
            'paymentMethod' => $service->getPaymentMethod($customer),
            'clientSecret' => $service->getClientSecret($customer, $invoice),
        ]);
    }

    public function paymentAuth(Request $request, $invoice_uid)
    {
        $invoice = Invoice::findByUid($invoice_uid);
        $service = $this->getPaymentService();
        $intent = \Stripe\PaymentIntent::retrieve($request->payment_intent_id);

        return view('cashier::stripe.paymentAuth', [
            'invoice' => $invoice,
            'service' => $service,
            'intent' => $intent,
        ]);
    }

    public function autoBillingDataUpdate(Request $request)
    {
        return redirect()->away(Billing::getReturnUrl());;
    }
}
