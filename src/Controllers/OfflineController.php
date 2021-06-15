<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Model\Setting;
use Acelle\Library\Facades\Billing;

use \Acelle\Model\Invoice;

class OfflineController extends Controller
{
    public function settings(Request $request)
    {
        $gateway = Billing::getGateway('offline');

        if ($request->isMethod('post')) {
            // validate
            $this->validate($request, [
                'payment_instruction' => 'required',
            ]);

            // save settings
            Setting::set('cashier.offline.payment_instruction', $request->payment_instruction);

            // enable if not validate
            if ($gateway->validate()) {
                \Acelle\Model\Setting::enablePaymentGateway($gateway->getType());
            }

            return redirect()->action('Admin\PaymentController@index');
        }

        return view('cashier::offline.settings', [
            'gateway' => $gateway,
        ]);
    }

    public function __construct(Request $request)
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
        return Billing::getGateway('offline');
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
        $service = $this->getPaymentService();
        $invoice = Invoice::findByUid($request->invoice_uid);

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
        
        return view('cashier::offline.checkout', [
            'service' => $service,
            'invoice' => $invoice,
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
        $service = $this->getPaymentService();
        $invoice = Invoice::findByUid($invoice_uid);

        // exceptions
        if (!$invoice->isPending()) {
            throw new \Exception('Invoice is not pending');
        }
        if (!$invoice->pendingTransaction()) {
            throw new \Exception('Pending invoice dose not have pending transaction');
        }
        
        // claim invoice
        $invoice->claim();
        
        return redirect()->action('AccountSubscriptionController@index');
    }
}
