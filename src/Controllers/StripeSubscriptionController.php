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

        if (!$invoice) {
            return redirect()->away(Billing::getReturnUrl() ?: url('/'))
                ->with('alert-error', trans('cashier::messages.stripe_subscription.invoice_not_found'));
        }

        $paymentGateway = PaymentGateway::findByUid($request->payment_gateway_id);

        if (!$paymentGateway) {
            return redirect()->away(Billing::getReturnUrl() ?: url('/'))
                ->with('alert-error', trans('cashier::messages.stripe_subscription.gateway_not_found'));
        }

        $service = $paymentGateway->getService();

        if ($request->return_url) {
            Billing::setReturnUrl($request->return_url);
        }

        if (!$invoice->isNew()) {
            return redirect()->away(Billing::getReturnUrl() ?: url('/'))
                ->with('alert-success', trans('cashier::messages.already_paid'));
        }

        // Get the subscription via order item
        $orderItem = $invoice->order->orderItems()->first();
        $subscription = $orderItem ? $orderItem->mapType()->subscription : null;

        if (!$subscription) {
            return redirect()->away(Billing::getReturnUrl() ?: url('/'))
                ->with('alert-error', trans('cashier::messages.stripe_subscription.no_subscription'));
        }

        // Find the remote plan mapping for this plan + gateway
        $mapping = PlanRemoteMapping::where('plan_id', $subscription->plan_id)
            ->where('payment_gateway_id', $paymentGateway->id)
            ->first();

        if (!$mapping) {
            return redirect()->action('SubscriptionController@payment', [
                'invoice_uid' => $invoice->uid,
            ])->with('alert-error', trans('messages.subscription.plan_not_mapped_to_gateway', [
                'plan' => $subscription->plan->name ?? 'Unknown',
                'gateway' => $paymentGateway->name,
            ]));
        }

        // Validate price/currency/interval match before allowing checkout
        $mismatches = $mapping->getMismatches();
        if (!empty($mismatches)) {
            $details = array_map(fn($m) => $m['message'], $mismatches);
            return redirect()->action('SubscriptionController@payment', [
                'invoice_uid' => $invoice->uid,
            ])->with('alert-error', trans('messages.subscription.plan_mapping_mismatch', [
                'plan' => $subscription->plan->name ?? 'Unknown',
                'gateway' => $paymentGateway->name,
                'details' => implode('. ', $details),
            ]));
        }

        if ($request->isMethod('post')) {
            $result = $service->createRemoteSubscription($invoice, $mapping->remote_plan_id, [
                'payment_method_id' => $request->payment_method_id,
            ]);

            if ($result->success) {
                // Save remote subscription data to local subscription
                $metadata = $result->metadata ?? [];
                $metadata['remote_status'] = $result->status ?? 'active';
                if ($result->currentPeriodEnd) {
                    $metadata['remote_period_end'] = $result->currentPeriodEnd->toDateTimeString();
                }
                $subscription->setRemoteSubscription(
                    $result->remoteSubscriptionId,
                    $result->remoteCustomerId,
                    $paymentGateway,
                    $metadata
                );

                // Create payment method record
                $paymentMethod = $invoice->customer->paymentMethods()->create([
                    'payment_gateway_id' => $paymentGateway->id,
                    'autobilling_data' => json_encode($result->paymentMethodData ?? []),
                    'can_auto_charge' => false,
                ]);

                $invoice->paySuccess($paymentMethod);

                // Return JSON for AJAX — JS will handle the redirect
                return response()->json([
                    'success' => true,
                    'redirect_url' => Billing::getReturnUrl() ?: url('/'),
                ]);
            } elseif ($result->status === 'requires_action') {
                return response()->json([
                    'requires_action' => true,
                    'client_secret' => $result->metadata['client_secret'] ?? null,
                    'remote_subscription_id' => $result->remoteSubscriptionId,
                ]);
            } else {
                return response()->json([
                    'error' => $result->error ?? trans('cashier::messages.stripe_subscription.payment_failed'),
                ], 422);
            }
        }

        return view('cashier::stripe-subscription.checkout', [
            'paymentGateway' => $paymentGateway,
            'invoice' => $invoice,
            'mapping' => $mapping,
            'clientSecret' => $service->getClientSecret($invoice->customer->uid, $invoice->billing_email ?: $invoice->customer->user->email),
            'publishableKey' => $service->getPublishableKey(),
        ]);
    }
}
