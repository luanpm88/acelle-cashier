<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Acelle\Model\Invoice;
use Acelle\Model\PaymentGateway;
use Acelle\Model\PlanRemoteMapping;
use Acelle\Library\Facades\Billing;

class StripeSubscriptionController extends Controller
{
    public function checkout(Request $request, $invoice_uid)
    {
        $invoice = Invoice::findByUid($invoice_uid);
        $paymentGateway = PaymentGateway::findByUid($request->payment_gateway_id);
        $service = $paymentGateway->getService();

        if ($request->return_url) {
            Billing::setReturnUrl($request->return_url);
        }

        if (!$invoice->isNew()) {
            throw new \Exception('Invoice is not new');
        }

        // Find the remote plan mapping for this plan + gateway
        $mapping = PlanRemoteMapping::where('plan_id', $invoice->subscription->plan_id)
            ->where('payment_gateway_id', $paymentGateway->id)
            ->first();

        if (!$mapping) {
            throw new \Exception('No remote plan mapping found for this plan and gateway. Please configure plan mapping in admin.');
        }

        if ($request->isMethod('post')) {
            $result = $service->createRemoteSubscription($invoice, $mapping->remote_plan_id, [
                'payment_method_id' => $request->payment_method_id,
            ]);

            if ($result->success) {
                // Save remote subscription data to local subscription
                $subscription = $invoice->subscription;
                $subscription->setRemoteSubscription(
                    $result->remoteSubscriptionId,
                    $result->remoteCustomerId,
                    $paymentGateway,
                    $result->metadata ?? []
                );

                // Create payment method record
                $paymentMethod = $invoice->customer->paymentMethods()->create([
                    'payment_gateway_id' => $paymentGateway->id,
                    'autobilling_data' => json_encode($result->paymentMethodData ?? []),
                    'can_auto_charge' => false,
                ]);

                $invoice->paySuccess($paymentMethod);
            } elseif ($result->status === 'requires_action') {
                return response()->json([
                    'requires_action' => true,
                    'client_secret' => $result->metadata['client_secret'] ?? null,
                    'remote_subscription_id' => $result->remoteSubscriptionId,
                ]);
            } else {
                return back()->withErrors(['payment' => $result->error ?? 'Payment failed']);
            }
        }

        return view('cashier::stripe-subscription.checkout', [
            'paymentGateway' => $paymentGateway,
            'invoice' => $invoice,
            'mapping' => $mapping,
            'clientSecret' => $service->getClientSecret($invoice->customer->uid, $invoice->customer->getBillingEmail()),
            'publishableKey' => $service->getPublishableKey(),
        ]);
    }
}
