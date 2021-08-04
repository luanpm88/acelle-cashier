<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Services\CoinpaymentsPaymentGateway;
use Acelle\Library\Facades\Billing;
use Acelle\Model\Setting;
use Acelle\Model\Invoice;
use Acelle\Library\TransactionVerificationResult;
use Acelle\Model\Transaction;
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
            // make validator
            $validator = \Validator::make($request->all(), [
                'merchant_id' => 'required',
                'public_key' => 'required',
                'private_key' => 'required',
                'merchant_id' => 'required',
                'ipn_secret' => 'required',
                'receive_currency' => 'required',
            ]);

            // test service
            $validator->after(function ($validator) use ($gateway, $request) {
                try {
                    $coinpayments = new CoinpaymentsPaymentGateway(
                        $request->merchant_id, $request->public_key, $request->private_key, $request->ipn_secret, $request->receive_currency);
                    $coinpayments->test();
                } catch(\Exception $e) {
                    $validator->errors()->add('field', 'Can not connect to ' . $gateway->getName() . '. Error: ' . $e->getMessage());
                }
            });

            // redirect if fails
            if ($validator->fails()) {
                return response()->view('cashier::coinpayments.settings', [
                    'gateway' => $gateway,
                    'errors' => $validator->errors(),
                ], 400);
            }

            // save settings
            Setting::set('cashier.coinpayments.merchant_id', $request->merchant_id);
            Setting::set('cashier.coinpayments.public_key', $request->public_key);
            Setting::set('cashier.coinpayments.private_key', $request->private_key);
            Setting::set('cashier.coinpayments.receive_currency', $request->receive_currency);
            Setting::set('cashier.coinpayments.ipn_secret', $request->ipn_secret);

            // enable if not validate
            if ($request->enable_gateway) {
                Billing::enablePaymentGateway($gateway->getType());
            }

            $request->session()->flash('alert-success', trans('cashier::messages.gateway.updated'));
            return redirect()->action('Admin\PaymentController@index');
        }

        return view('cashier::coinpayments.settings', [
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
            return redirect()->action('SubscriptionController@index');
        }

        // exceptions
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        // free plan. No charge
        if ($invoice->total() == 0) {
            $invoice->checkout($service, function($invoice) {
                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_DONE);
            });

            return redirect()->action('SubscriptionController@index');
        }

        if ($request->isMethod('post')) {
            $service->charge($invoice);

            return redirect()->action('SubscriptionController@index');
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
}
