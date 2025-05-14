<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Acelle\Library\Facades\Billing;
use Acelle\Library\TransactionResult;
use Acelle\Model\Invoice;
use Acelle\Model\PaymentGateway;

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

        // exceptions
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        // Payment method
        $paymentMethod = $invoice->customer->paymentMethods()->updateOrCreate(
            [
                'payment_gateway_id' => $paymentGateway->id,
            ],
            [
                'can_auto_charge' => false,
            ]
        );
        
        // claim invoice
        $invoice->checkout($paymentMethod, function($invoice) {
            return new TransactionResult(TransactionResult::RESULT_PENDING);
        });
        
        return redirect()->away(Billing::getReturnUrl());
    }
}
