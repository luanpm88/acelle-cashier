<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Library\Facades\Billing;
use App\Model\Invoice;
use App\Model\PaymentGateway;

class PaystackController extends Controller
{
    /**
     * Subscription checkout page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
    **/
    public function checkout(Request $request, $invoice_uid)
    {
        $invoice = Invoice::findByUid($invoice_uid);

        // Service
        $paymentGateway = PaymentGateway::findByUid($request->payment_gateway_id);
        $service = $paymentGateway->getService();
        
        // Set return URL for billing
        if ($request->return_url) {
            Billing::setReturnUrl($request->return_url);
        }

        // exceptions
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        if ($request->isMethod('post')) {
            try {
                // check pay
                $result = $service->verifyPayment($request->reference);

                // Payment method
                $autobillingData = json_encode([
                    'authorization_code' => $result['data']['authorization']['authorization_code'],
                    'email' => $result['data']['customer']['email'],
                    'last_4' => $result['data']['authorization']['last4'],
                    'card_type' => ucfirst($result['data']['authorization']['card_type']),
                ]);
                $paymentMethod = $invoice->customer->paymentMethods()->create(
                    [
                        'payment_gateway_id' => $paymentGateway->id,
                        'autobilling_data' => $autobillingData,
                        'can_auto_charge' => true,
                    ]
                );

                // success
                $invoice->paySuccess($paymentMethod);

                return redirect()->away(Billing::getReturnUrl());
            } catch (\Throwable $e) {
                // return with error message
                return redirect()->away(Billing::getReturnUrl())
                    ->with('alert-error', $e->getMessage());
            }
        }

        return view('cashier::paystack.checkout', [
            'service' => $service,
            'invoice' => $invoice,
            'paymentGateway' => $paymentGateway,
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

        return redirect()->away(Billing::getReturnUrl());;
    }
}
