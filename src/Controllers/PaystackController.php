<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Services\StripePaymentGateway;
use Acelle\Library\Facades\Billing;
use Acelle\Model\Setting;
use Acelle\Library\AutoBillingData;
use \Acelle\Model\Invoice;

class PaystackController extends Controller
{
    public function settings(Request $request)
    {
        $gateway = $this->getPaymentService();

        if ($request->isMethod('post')) {
            
            // validate
            $this->validate($request, [
                'public_key' => 'required',
                'secret_key' => 'required',
            ]);

            // save settings
            Setting::set('cashier.paystack.public_key', $request->public_key);
            Setting::set('cashier.paystack.secret_key', $request->secret_key);

            // enable if not validate
            if ($gateway->validate()) {
                \Acelle\Model\Setting::enablePaymentGateway($gateway->getType());
            }

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
        if (!$invoice->isPending()) {
            throw new \Exception('Invoice is not pending');
        }
        if (!$invoice->pendingTransaction()) {
            throw new \Exception('Pending invoice dose not have pending transaction');
        }

        // free plan. No charge
        if ($invoice->total() == 0) {
            $invoice->fulfill();

            return redirect()->action('AccountSubscriptionController@index');
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

                $invoice->fulfill();

                return redirect()->action('AccountSubscriptionController@index');
            } catch (\Exception $e) {
                // return with error message
                $request->session()->flash('alert-error', $e->getMessage());
                return redirect()->action('AccountSubscriptionController@index');
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
        if (!$invoice->isPending()) {
            throw new \Exception('Invoice is not pending');
        }
        if (!$invoice->pendingTransaction()) {
            throw new \Exception('Pending invoice dose not have pending transaction');
        }
        
        // autopay
        $service->autoCharge($invoice);

        return redirect()->action('AccountSubscriptionController@index');
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
        // Get current customer
        $service = $this->getPaymentService();
        
        return redirect()->action('AccountSubscriptionController@index');
    }
}
