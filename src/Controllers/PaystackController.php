<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Services\PaystackPaymentGateway;
use Acelle\Library\Facades\Billing;
use Acelle\Model\Setting;
use Acelle\Library\AutoBillingData;
use Acelle\Model\Invoice;
use Acelle\Library\TransactionVerificationResult;
use Acelle\Model\Transaction;

class PaystackController extends Controller
{
    public function settings(Request $request)
    {
        $gateway = $this->getPaymentService();

        if ($request->isMethod('post')) {
            // make validator
            $validator = \Validator::make($request->all(), [
                'public_key' => 'required',
                'secret_key' => 'required',
            ]);

            // test service
            $validator->after(function ($validator) use ($gateway, $request) {
                try {
                    $paystack = new PaystackPaymentGateway($request->public_key, $request->secret_key);
                    $paystack->test();
                } catch(\Exception $e) {
                    $validator->errors()->add('field', 'Can not connect to ' . $gateway->getName() . '. Error: ' . $e->getMessage());
                }
            });

            // redirect if fails
            if ($validator->fails()) {
                return response()->view('cashier::paystack.settings', [
                    'gateway' => $gateway,
                    'errors' => $validator->errors(),
                ], 400);
            }

            // save settings
            Setting::set('cashier.paystack.public_key', $request->public_key);
            Setting::set('cashier.paystack.secret_key', $request->secret_key);

            // enable if not validate
            if ($request->enable_gateway) {
                Billing::enablePaymentGateway($gateway->getType());
            }

            $request->session()->flash('alert-success', trans('cashier::messages.gateway.updated'));
            return redirect()->action('Admin\PaymentController@index');
        }

        return view('cashier::paystack.settings', [
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
        return Billing::getGateway('paystack');
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
                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_DONE);
            });

            return redirect()->action('SubscriptionController@index');
        }

        if ($service->getCard($invoice->customer)) {
            return view('cashier::paystack.charging', [
                'service' => $service,
                'invoice' => $invoice,
            ]);
        }

        if ($request->isMethod('post')) {
            try {
                // check pay
                $service->verifyPayment($invoice, $request->reference);

                $invoice->checkout($service, function($invoice) {
                    return new TransactionVerificationResult(TransactionVerificationResult::RESULT_DONE);
                });

                return redirect()->action('SubscriptionController@index');
            } catch (\Exception $e) {
                // return with error message
                $request->session()->flash('alert-error', $e->getMessage());
                return redirect()->action('SubscriptionController@index');
            }
        }

        return view('cashier::paystack.checkout', [
            'service' => $service,
            'invoice' => $invoice,
        ]);
    }

    /**
     * Subscription checkout page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
    **/
    public function charge(Request $request, $invoice_uid)
    {
        $service = $this->getPaymentService();
        $invoice = Invoice::findByUid($invoice_uid);

        // exceptions
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }
        
        // autopay
        $service->autoCharge($invoice);

        return redirect()->action('SubscriptionController@index');
    }

    /**
     * Fix transation.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function autoBillingDataUpdate(Request $request)
    {
        return redirect()->action('SubscriptionController@index');
    }
}
