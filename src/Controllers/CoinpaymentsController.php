<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Services\CoinpaymentsPaymentGateway;

use \Acelle\Model\Invoice;

class CoinpaymentsController extends Controller
{
    public function __construct()
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
        return \Acelle\Model\Setting::getPaymentGateway('coinpayments');
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
            $result = $service->charge($invoice);

            // redirect to checkout page
            return redirect()->away($invoice->getMetadata()['checkout_url']);
        }

        if ($invoice->getMetadata() !== null && isset($invoice->getMetadata()['txn_id'])) {
            $service->checkPay($invoice);

            return view('cashier::coinpayments.pending', [
                'service' => $service,
                'invoice' => $invoice,
            ]);
        } else {
            return view('cashier::coinpayments.charging', [
                'service' => $service,
                'invoice' => $invoice,
            ]);
        }
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
            'method' => 'coinpayments',
            'user_id' => $request->user()->customer->getBillableEmail(),
        ]);

        // Save return url
        if ($request->return_url) {
            return redirect()->away($request->return_url);
        }
        
        return view('cashier::coinpayments.connect', [
            'return_url' => $this->getReturnUrl($request),
        ]);
    }
}
