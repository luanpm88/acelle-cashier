<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Model\Setting;
use Acelle\Library\Facades\Billing;
use Acelle\Library\TransactionVerificationResult;
use Acelle\Model\Invoice;
use Acelle\Model\Transaction;

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
            if ($request->enable_gateway) {
                Billing::enablePaymentGateway($gateway->getType());
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
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }
        
        // claim invoice
        $invoice->checkout($service, function($invoice) {
            return new TransactionVerificationResult(TransactionVerificationResult::RESULT_STILL_PENDING);
        });
        
        return redirect()->action('SubscriptionController@index');
    }
}
