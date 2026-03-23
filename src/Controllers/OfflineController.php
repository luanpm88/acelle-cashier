<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Library\Facades\Billing;
use App\Model\Invoice;
use App\Model\PaymentGateway;

class OfflineController extends Controller
{
    public function __construct(Request $request)
    {
        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }
    
    /**
     * Subscription checkout page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function checkout(Request $request)
    {
        $invoice = Invoice::findByUid($request->invoice_uid);

        // Service
        $paymentGateway = PaymentGateway::findByUid($request->payment_gateway_id);

        // exceptions
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }
        
        return view('cashier::offline.checkout', [
            'invoice' => $invoice,
            'paymentGateway' => $paymentGateway,
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
        $invoice = Invoice::findByUid($invoice_uid);

        // Service
        $paymentGateway = PaymentGateway::findByUid($request->payment_gateway_id);

        // Set return URL for billing
        if ($request->return_url) {
            Billing::setReturnUrl($request->return_url);
        }

        // exceptions
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        // Payment method
        $paymentMethod = $invoice->customer->paymentMethods()->create(
            [
                'payment_gateway_id' => $paymentGateway->id,
                'can_auto_charge' => false,
            ]
        );
        
        // claim invoice
        $invoice->payPending($paymentMethod);
        
        return redirect()->away(Billing::getReturnUrl());
    }
}
