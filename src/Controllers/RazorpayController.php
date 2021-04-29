<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;

use \Acelle\Model\Invoice;

class RazorpayController extends Controller
{
    public function __construct()
    {
        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

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
        return Cashier::getPaymentGateway('razorpay');
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

        // not waiting
        if (!$invoice->pendingTransaction() || $invoice->isPaid()) {
            return redirect()->away($this->getReturnUrl($request));
        }

        // free plan. No charge
        if ($invoice->total() == 0) {
            $invoice->approve();

            return redirect()->away($this->getReturnUrl($request));
        }

        if ($request->isMethod('post')) {
            $service->charge($request);

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }

        // create order
        try {
            $order = $service->createRazorpayOrder($invoice);
            $customer = $service->getRazorpayCustomer($invoice);
        } catch (\Exception $e) {
            // pay failed
            $invoice->payFailed($e->getMessage());

            return redirect()->away($this->getReturnUrl($request));
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
            'user_id' => $request->user()->customer->getBillableEmail(),
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