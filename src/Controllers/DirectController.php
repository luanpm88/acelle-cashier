<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;

use \Acelle\Model\Invoice;

class DirectController extends Controller
{
    public function __construct(Request $request)
    {
        \Carbon\Carbon::setToStringFormat('jS \o\f F');
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
     * Get current payment service.
     *
     * @return \Illuminate\Http\Response
     **/
    public function getPaymentService()
    {
        return \Acelle\Model\Setting::getPaymentGateway('direct');
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

            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::direct.checkout', [
            'service' => $service,
            'invoice' => $invoice,
        ]);
    }
    
    /**
     * Claim payment.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function claim(Request $request, $invoice_uid)
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
        
        // claim invoice
        $invoice->claim();
        
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
        $service = $this->getPaymentService();

        $request->user()->customer->updatePaymentMethod([
            'method' => 'direct',
            'description' => trans('messages.payment.direct.description'),
        ]);

        // Save return url
        if ($request->return_url) {
            return redirect()->away($request->return_url);
        }

        return view('cashier::direct.connect', [
            'return_url' => $this->getReturnUrl($request),
        ]);
    }
}
