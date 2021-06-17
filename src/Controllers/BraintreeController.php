<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Services\BraintreePaymentGateway;
use Acelle\Library\Facades\Billing;
use Acelle\Model\Setting;
use Acelle\Library\AutoBillingData;
use \Acelle\Model\Invoice;

class BraintreeController extends Controller
{
    public function settings(Request $request)
    {
        $gateway = Billing::getGateway('braintree');

        if ($request->isMethod('post')) {
            
            // validate
            $this->validate($request, [
                'environment' => 'required',
                'merchant_id' => 'required',
                'public_key' => 'required',
                'private_key' => 'required',
            ]);

            // save settings
            Setting::set('cashier.braintree.environment', $request->environment);
            Setting::set('cashier.braintree.merchant_id', $request->merchant_id);
            Setting::set('cashier.braintree.public_key', $request->public_key);
            Setting::set('cashier.braintree.private_key', $request->private_key);

            // enable if not validate
            if ($gateway->validate()) {
                \Acelle\Model\Setting::enablePaymentGateway($gateway->getType());
            }

            return redirect()->action('Admin\PaymentController@index');
        }

        return view('cashier::braintree.settings', [
            'gateway' => $gateway,
        ]);
    }

    /**
     * Get current payment service.
     *
     * @return \Illuminate\Http\Response
     **/
    public function getPaymentService()
    {
        return Billing::getGateway('braintree');
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

        // exceptions
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        // not waiting
        if (!$invoice->pendingTransaction() || $invoice->isPaid()) {
            return redirect()->action('AccountSubscriptionController@index');
        }

        // free plan. No charge
        if ($invoice->total() == 0) {
            $invoice->fulfill();

            return redirect()->action('AccountSubscriptionController@index');
        }

        // Customer has no card
        if (!$service->hasCard($customer)) {
            // connect again
            return redirect()->away(
                $service->getAutoBillingDataUpdateUrl(
                    $service->getCheckoutUrl($invoice)
                )
            );
        }

        if ($request->isMethod('post')) {
            $result = $service->autoCharge($invoice);

            // return back
            return redirect()->action('AccountSubscriptionController@index');
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
    public function autoBillingDataUpdate(Request $request)
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

            // get card
            $card = $service->getCardInformation($request->user()->customer);

            // update auto billing data
            $autoBillingData = new AutoBillingData($service, [
                'paymentMethodToken' => $card->token,
                'card_last4' => $card->last4,
                'card_type' => $card->cardType,
            ]);
            $request->user()->customer->setAutoBillingData($autoBillingData);
            
            // return to billing page
            $request->session()->flash('alert-success', trans('cashier::messages.braintree.connected'));
            return redirect()->action('AccountSubscriptionController@index');
        }
        
        return view('cashier::braintree.autoBillingDataUpdate', [
            'service' => $service,
            'clientToken' => $service->serviceGateway->clientToken()->generate(),
            'cardInfo' => $card,
        ]);
    }
}
