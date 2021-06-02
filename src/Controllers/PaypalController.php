<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Services\PaypalPaymentGateway;

use \Acelle\Model\Invoice;

class PaypalController extends Controller
{
    public function __construct()
    {
        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    /**
     * Get current payment service.
     *
     * @return \Illuminate\Http\Response
     **/
    public function getPaymentService()
    {
        return \Acelle\Model\Setting::getPaymentGateway('paypal');
    }

    /**
     * Get return url.
     *
     * @return string
     **/
    public function getReturnUrl(Request $request)
    {
        $return_url = $request->session()->get('checkout_return_url', Cashier::public_url('/'));
        if (!$return_url) {
            $return_url = Cashier::public_url('/');
        }

        return $return_url;
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

        // not pending
        if (!$invoice->isPending()) {
            return redirect()->away($this->getReturnUrl($request));
        }

        // free plan. No charge
        if ($invoice->total() == 0) {
            $invoice->fulfill();

            return redirect()->away($this->getReturnUrl($request));
        }

        if ($request->isMethod('post')) {
            $result = $service->charge($invoice, [
                'orderID' => $request->orderID,
            ]);

            // return back
            return redirect()->away($this->getReturnUrl($request));
        }

        return view('cashier::paypal.checkout', [
            'invoice' => $invoice,
            'service' => $service,
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
        $request->user()->customer->updatePaymentMethod([
            'method' => 'paypal',
            'user_id' => $request->user()->customer->getBillableEmail(),
        ]);

        // Save return url
        if ($request->return_url) {
            return redirect()->away($request->return_url);
        }
        
        return view('cashier::paypal.connect', [
            'return_url' => $this->getReturnUrl($request),
        ]);
    }
}
