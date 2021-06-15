<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Services\CoinpaymentsPaymentGateway;
use Acelle\Library\Facades\Billing;
use Acelle\Model\Setting;

use \Acelle\Model\Invoice;
use Acelle\Library\AutoBillingData;

class CoinpaymentsController extends Controller
{
    public function __construct()
    {
        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    public function settings(Request $request)
    {
        $gateway = $this->getPaymentService();

        if ($request->isMethod('post')) {
            // validate
            $this->validate($request, [
                'merchant_id' => 'required',
                'public_key' => 'required',
                'private_key' => 'required',
                'merchant_id' => 'required',
                'ipn_secret' => 'required',
                'receive_currency' => 'required',
            ]);

            // save settings
            Setting::set('cashier.coinpayments.merchant_id', $request->merchant_id);
            Setting::set('cashier.coinpayments.public_key', $request->public_key);
            Setting::set('cashier.coinpayments.private_key', $request->private_key);
            Setting::set('cashier.coinpayments.receive_currency', $request->receive_currency);
            Setting::set('cashier.coinpayments.ipn_secret', $request->ipn_secret);

            // enable if not validate
            if ($gateway->validate()) {
                \Acelle\Model\Setting::enablePaymentGateway($gateway->getType());
            }

            return redirect()->action('Admin\PaymentController@index');
        }

        return view('cashier::coinpayments.settings', [
            'gateway' => $gateway,
        ]);
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
        return Billing::getGateway('coinpayments');
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

        // already paid
        if ($invoice->isPaid()) {
            return redirect()->action('AccountSubscriptionController@index');
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

            return redirect()->action('AccountSubscriptionController@index');
        }

        if ($request->isMethod('post')) {
            try {
                $service->charge($invoice);

                return redirect()->away($service->getData($invoice)['checkout_url']);
            } catch (\Exception $e) {
                $request->session()->flash('alert-error', $e->getMessage());
                return redirect()->action('AccountSubscriptionController@index');
            }
        }

        if ($service->getData($invoice) !== null && isset($service->getData($invoice)['txn_id'])) {
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
