<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Acelle\Model\Invoice;
use Acelle\Model\PaymentGateway;


class StripeController extends Controller
{
    public function getCheckoutUrl($invoice, $payment_gateway_id)
    {
        return action("\Acelle\Cashier\Controllers\StripeController@checkout", [
            'invoice_uid' => $invoice->uid,
            'payment_gateway_id' => $payment_gateway_id,
        ]);
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
        $invoice = Invoice::findByUid($invoice_uid);

        // Service
        $paymentGateway = PaymentGateway::findByUid($request->payment_gateway_id);

        // exceptions
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        if ($request->isMethod('post')) {
            $stripeCustomer = $paymentGateway->getService()->getStripeCustomer($invoice->customer->uid);
            $paymentMethod = $paymentGateway->getService()->getPaymentMethod($request->payment_method_id);

            // Payment method
            $autobillingData = json_encode([
                'payment_method_id' => $request->payment_method_id,
                'customer_id' => $stripeCustomer->id,
                'card_type' => ucfirst($paymentMethod->card->brand),
                'last_4' => $paymentMethod->card->last4,
            ]);
            $paymentMethod = $invoice->customer->paymentMethods()->create(
                [
                    'payment_gateway_id' => $paymentGateway->id,
                    'autobilling_data' => $autobillingData,
                    'can_auto_charge' => true,
                ]
            );

            // invoice checkout
            $invoice->paySuccess($paymentMethod);
        }

        return view('cashier::stripe.checkout', [
            'paymentGateway' => $paymentGateway,
            'invoice' => $invoice,
            'clientSecret' => $paymentGateway->getService()->getClientSecret($invoice->customer->uid, $invoice),
            'publishableKey' => $paymentGateway->getService()->getPublishableKey(),
        ]);
    }

    public function paymentAuth(Request $request, $invoice_uid, $payment_gateway_id)
    {
        $invoice = Invoice::findByUid($invoice_uid);

        return redirect()->away($this->getCheckoutUrl($invoice, $payment_gateway_id));
    }
}
