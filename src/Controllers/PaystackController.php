<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Services\StripePaymentGateway;

use \Acelle\Model\Invoice;

class PaystackController extends Controller
{
    public function getReturnUrl(Request $request) {
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
        return \Acelle\Model\Setting::getPaymentGateway('paystack');
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

        // not new
        if (!$invoice->pendingTransaction() || $invoice->isPaid()) {
            return redirect()->away($this->getReturnUrl($request));
        }

        // free plan. No charge
        if ($invoice->total() == 0) {
            $invoice->approve();

            return redirect()->away($this->getReturnUrl($request));
        }

        if($service->getCard($invoice->customer)) {
            return view('cashier::paystack.charging', [
                'service' => $service,
                'invoice' => $invoice,
            ]);
        }

        if ($request->isMethod('post')) {
            try {
                // check pay
                $service->verifyPayment($invoice, $request->reference);

                $invoice->approve();

                return redirect()->away($this->getReturnUrl($request));
            } catch (\Exception $e) {
                // return with error message
                $request->session()->flash('alert-error', $e->getMessage());
                return redirect()->away($this->getReturnUrl($request));
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
        
        // autopay
        $service->charge($invoice);

        return redirect()->away($this->getReturnUrl($request));
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
        // Get current customer
        $service = $this->getPaymentService();

        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        $request->user()->customer->updatePaymentMethod([
            'method' => 'paystack',
            'user_id' => $request->user()->customer->getBillableEmail(),
        ]);

        // Save return url
        if ($request->return_url) {
            return redirect()->away($request->return_url);
        }

        return view('cashier::paystack.connect', [
            'return_url' => $this->getReturnUrl($request),
        ]);
    }
}