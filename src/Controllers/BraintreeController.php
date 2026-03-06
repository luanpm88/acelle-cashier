<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Acelle\Library\Facades\Billing;
use Acelle\Model\PaymentGateway;
use Acelle\Model\Invoice;

class BraintreeController extends Controller
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
        // Service
        $paymentGateway = PaymentGateway::findByUid($request->payment_gateway_id);
        $service = $paymentGateway->getService();

        $invoice = Invoice::findByUid($invoice_uid);
        $card = $service->getCardInformation($invoice->billing_email);

        // Set return URL for billing
        if ($request->return_url) {
            Billing::setReturnUrl($request->return_url);
        }
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }

        // exceptions
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        if ($request->isMethod('post')) {
            // update card
            $service->updateCard($invoice->billing_email, $request->nonce);

            // get card
            $card = $service->getCardInformation($invoice->billing_email);

            // handle null card
            if (is_null($card)) {
                return redirect()->action("\Acelle\Cashier\Controllers\BraintreeController@checkout", [
                    'invoice_uid' => $invoice->uid,
                    'payment_gateway_id' => $request->payment_gateway_id,
                ])->with('alert-warning', 'Unable to retrieve card information. Please try again!');
            }

            // Payment method
            $autobillingData = json_encode([
                'payment_method_token' => $card->token,
                'last_4' => $card->last4,
                'card_type' => ucfirst($card->cardType),
            ]);
            $paymentMethod = $invoice->customer->paymentMethods()->create(
                [
                    'payment_gateway_id' => $paymentGateway->id,
                    'autobilling_data' => $autobillingData,
                    'can_auto_charge' => true,
                ]
            );

            // auto charge
            $service->autoCharge($invoice, $paymentMethod);

            // return back
            return redirect()->away(Billing::getReturnUrl());
        }

        return view('cashier::braintree.checkout', [
            'service' => $service,
            'clientToken' => $service->serviceGateway->clientToken()->generate(),
            'cardInfo' => $card,
            'invoice' => $invoice,
            'paymentGateway' => $paymentGateway,
        ]);
    }
}
