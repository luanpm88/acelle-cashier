<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Library\Facades\Billing;
use App\Model\Invoice;
use App\Model\PaymentGateway;

class PaypalController extends Controller
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
            // Payment method
            $paymentMethod = $invoice->customer->paymentMethods()->create(
                [
                    'payment_gateway_id' => $paymentGateway->id,
                    'can_auto_charge' => false,
                ]
            );

            // charge invoice
            $service->checkOrderID($request->orderID);

            // success
            $invoice->paySuccess($paymentMethod);

            // return back
            return redirect()->away(Billing::getReturnUrl());;
        }

        return view('cashier::paypal.checkout', [
            'invoice' => $invoice,
            'service' => $service,
            'paymentGateway' => $paymentGateway,
        ]);
    }
}
