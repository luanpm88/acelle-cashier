<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Library\Facades\Billing;
use Acelle\Model\Setting;

use \Acelle\Model\Invoice;
use Acelle\Library\AutoBillingData;

class RazorpayController extends Controller
{
    public function __construct()
    {
        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    public function settings(Request $request)
    {
        $gateway = $this->getPaymentService();

        if ($request->isMethod('post')) {
            // validate
            $this->validate($request, [
                'key_id' => 'required',
                'key_secret' => 'required',
            ]);

            // save settings
            Setting::set('cashier.razorpay.key_id', $request->key_id);
            Setting::set('cashier.razorpay.key_secret', $request->key_secret);

            // enable if not validate
            if ($gateway->validate()) {
                \Acelle\Model\Setting::enablePaymentGateway($gateway->getType());
            }

            return redirect()->action('Admin\PaymentController@index');
        }

        return view('cashier::razorpay.settings', [
            'gateway' => $gateway,
        ]);
    }

    public function getReturnUrl(Request $request)
    {
        $return_url = $request->session()->get('checkout_return_url', Cashier::public_url('/'));
        if (!$return_url) {
            $return_url = Cashier::public_url('/');
        }

        return $return_url;
    }

    /**
     * Get current payment service.
     *
     * @return \Illuminate\Http\Response
     **/
    public function getPaymentService()
    {
        return Billing::getGateway('razorpay');
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
            $invoice->fulfill();

            return redirect()->action('AccountSubscriptionController@index');
        }

        if ($request->isMethod('post')) {
            try {
                $service->charge($invoice, $request);
            } catch (\Exception $e) {    
                $request->session()->flash('alert-error', $e->getMessage());
                return redirect()->action('AccountSubscriptionController@index');
            }

            // Redirect to my subscription page
            return redirect()->action('AccountSubscriptionController@index');
        }

        // create order
        try {
            $order = $service->createRazorpayOrder($invoice);
            $customer = $service->getRazorpayCustomer($invoice);
        } catch (\Exception $e) {
            // pay failed
            $invoice->payFailed($e->getMessage());

            $request->session()->flash('alert-error', $e->getMessage());
            return redirect()->action('AccountSubscriptionController@index');
        }

        return view('cashier::razorpay.checkout', [
            'invoice' => $invoice,
            'service' => $service,
            'order' => $order,
            'customer' => $customer,
        ]);
    }

    /**
     * Fix transation.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function connect(Request $request)
    {
        $service = $this->getPaymentService();

        $request->user()->customer->updatePaymentMethod([
            'method' => 'razorpay',
            'user_id' => $request->user()->customer->user->email,
        ]);

        // Save return url
        if ($request->return_url) {
            return redirect()->away($request->return_url);
        }
        
        return view('cashier::razorpay.connect', [
            'return_url' => $this->getReturnUrl($request),
        ]);
    }
}
