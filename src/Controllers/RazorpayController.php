<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Acelle\Library\Facades\Billing;
use Acelle\Model\Invoice;
use Acelle\Library\TransactionResult;
use Acelle\Model\PaymentGateway;

class RazorpayController extends Controller
{
    public function __construct()
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
    public function checkout(Request $request, $invoice_uid)
    {
        $invoice = Invoice::findByUid($invoice_uid);

        // Service
        $paymentGateway = PaymentGateway::findByUid($request->payment_gateway_id);
        $service = $paymentGateway->getService();
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }

        // exceptions
        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        if ($request->isMethod('post')) {
            try {
                $service->charge($invoice, $request);
            } catch (\Exception $e) {    
                $request->session()->flash('alert-error', $e->getMessage());
                return redirect()->away(Billing::getReturnUrl());;
            }

            // Redirect to my subscription page
            return redirect()->away(Billing::getReturnUrl());;
        }

        // create order
        try {
            $order = $service->createRazorpayOrder($invoice);
            $customer = $service->getRazorpayCustomer($invoice);
        } catch (\Exception $e) {
            session()->flash('alert-error', $e->getMessage());
            return redirect()->away(Billing::getReturnUrl());
        }

        return view('cashier::razorpay.checkout', [
            'invoice' => $invoice,
            'service' => $service,
            'order' => $order,
            'customer' => $customer,
            'paymentGateway' => $paymentGateway,
        ]);
    }
}
