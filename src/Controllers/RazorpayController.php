<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Library\Facades\Billing;
use App\Model\Invoice;
use App\Model\PaymentGateway;

class RazorpayController extends Controller
{
    protected function findOwnedInvoice(Request $request, string $invoiceUid): ?Invoice
    {
        $customer = $request->user()?->customer;

        if (!$customer) {
            return null;
        }

        return $customer->invoices()->where('invoices.uid', $invoiceUid)->first();
    }

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
        $invoice = $this->findOwnedInvoice($request, $invoice_uid);

        if (!$invoice) {
            return redirect()->away(Billing::getReturnUrl() ?: url('/'))
                ->with('alert-error', 'Invoice not found.');
        }

        // Service
        $paymentGateway = PaymentGateway::findByUid($request->payment_gateway_id);
        $service = $paymentGateway->getService();
        
        // Set return URL for billing
        if ($request->return_url) {
            Billing::setReturnUrl($request->return_url);
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
