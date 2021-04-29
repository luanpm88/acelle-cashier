<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Services\BraintreePaymentGateway;

use \Acelle\Model\Invoice;

class BraintreeController extends Controller
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
        return \Acelle\Model\Setting::getPaymentGateway('braintree');
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

        // Customer has no card
        if(!$service->hasCard($customer)) {
            // connect again
            return redirect()->away(
                $service->getConnectUrl(
                    $service->getCheckoutUrl($invoice, $this->getReturnUrl($request))
                )
            );
        }

        if ($request->isMethod('post')) {
            $result = $service->charge($invoice);

            // return back
            return redirect()->away($this->getReturnUrl($request));
        }

        return view('cashier::braintree.charging', [
            'invoice' => $invoice,
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
        // Get current customer
        $service = $this->getPaymentService();

        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }

        // get card
        $card = $service->getCardInformation($request->user()->customer);
        
        if ($request->isMethod('post')) {
            if (!$request->use_current_card) {
                // update card
                $service->updateCard($request->user()->customer, $request->nonce);
            }

            // set gateway
            $card = $service->getCardInformation($request->user()->customer);
            $request->user()->customer->updatePaymentMethod([
                'method' => 'braintree',
                'user_id' => $request->user()->customer->getBillableEmail(),
                'card_last4' => $card->last4,
                'card_type' => $card->cardType,
            ]);
            
            // return to billing page
            $request->session()->flash('alert-success', trans('cashier::messages.braintree.connected'));
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::braintree.connect', [
            'service' => $service,
            'clientToken' => $service->serviceGateway->clientToken()->generate(),
            'cardInfo' => $card,
        ]);
    }
}