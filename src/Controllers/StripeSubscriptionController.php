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
                ->with('alert-error', 'Invoice not found.');
        }

        $paymentGateway = PaymentGateway::findByUid($request->payment_gateway_id);

        if (!$paymentGateway) {
            return redirect()->away(Billing::getReturnUrl() ?: url('/'))
                ->with('alert-error', 'Payment gateway not found.');
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
                ->with('alert-error', 'No subscription found for this invoice.');
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

        if ($request->isMethod('post')) {
            $result = $service->createRemoteSubscription($invoice, $mapping->remote_plan_id, [
                'payment_method_id' => $request->payment_method_id,
            ]);

            if ($result->success) {
                // Enrich metadata with full remote state so admin UI shows correct status immediately
                $enrichedMetadata = array_merge($result->metadata ?? [], [
                    'remote_status' => $result->status,
                    'remote_plan_id' => $result->remotePlanId ?? $mapping->remote_plan_id,
                    'remote_customer_id' => $result->remoteCustomerId,
                ]);
                if ($result->currentPeriodEnd) {
                    $enrichedMetadata['remote_period_end'] = $result->currentPeriodEnd->toDateTimeString();
                }

                // Save remote subscription data to local subscription
                $subscription->setRemoteSubscription(
                    $result->remoteSubscriptionId,
                    $result->remoteCustomerId,
                    $paymentGateway,
                    $enrichedMetadata
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
                // Save remote subscription data NOW — before 3DS.
                // If the user closes the browser during 3DS, the webhook
                // can still find the subscription by remote_subscription_id.
                $enriched3dsMetadata = array_merge($result->metadata ?? [], [
                    'remote_status' => 'incomplete',
                    'remote_plan_id' => $result->remotePlanId ?? $mapping->remote_plan_id,
                    'remote_customer_id' => $result->remoteCustomerId,
                ]);
                if ($result->currentPeriodEnd) {
                    $enriched3dsMetadata['remote_period_end'] = $result->currentPeriodEnd->toDateTimeString();
                }

                $subscription->setRemoteSubscription(
                    $result->remoteSubscriptionId,
                    $result->remoteCustomerId,
                    $paymentGateway,
                    $enriched3dsMetadata
                );

                return response()->json([
                    'requires_action' => true,
                    'client_secret' => $result->metadata['client_secret'] ?? null,
                    'invoice_uid' => $invoice->uid,
                    'payment_gateway_id' => $paymentGateway->uid,
                ]);
            } else {
                return response()->json([
                    'error' => $result->error ?? 'Payment failed',
                ], 422);
            }
        }

        return view('cashier::stripe-subscription.checkout', [
            'paymentGateway' => $paymentGateway,
            'invoice' => $invoice,
            'mapping' => $mapping,
            'publishableKey' => $service->getPublishableKey(),
        ]);
    }

    /**
     * Confirm a subscription after 3D Secure / SCA authentication.
     * Called by the JS client after stripe.confirmCardPayment() succeeds.
     */
    public function confirm(Request $request, $invoice_uid)
    {
        $invoice = Invoice::findByUid($invoice_uid);

        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found.'], 404);
        }

        $paymentGateway = PaymentGateway::findByUid($request->payment_gateway_id);

        if (!$paymentGateway) {
            return response()->json(['error' => 'Payment gateway not found.'], 404);
        }

        $orderItem = $invoice->order->orderItems()->first();
        $subscription = $orderItem ? $orderItem->mapType()->subscription : null;

        if (!$subscription || !$subscription->remote_subscription_id) {
            return response()->json(['error' => 'No remote subscription found.'], 422);
        }

        // If already paid, just redirect
        if (!$invoice->isNew()) {
            return response()->json([
                'success' => true,
                'redirect_url' => Billing::getReturnUrl() ?: url('/'),
            ]);
        }

        // Verify the remote subscription is now active
        $service = $paymentGateway->getService();
        try {
            $remoteSub = $service->getRemoteSubscription($subscription->remote_subscription_id);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not verify remote subscription: ' . $e->getMessage()], 422);
        }

        if (!in_array($remoteSub->status, ['active', 'trialing'])) {
            return response()->json(['error' => 'Subscription is not active yet (status: ' . $remoteSub->status . '). Please try again.'], 422);
        }

        // Update metadata with full remote state now that subscription is confirmed
        $meta = $subscription->getRemoteMetadataArray();
        $meta['remote_status'] = $remoteSub->status;
        $meta['remote_plan_id'] = $remoteSub->remotePlanId;
        $meta['remote_customer_id'] = $remoteSub->remoteCustomerId;
        if ($remoteSub->currentPeriodEnd) {
            $meta['remote_period_end'] = $remoteSub->currentPeriodEnd->toDateTimeString();
        }
        if ($remoteSub->currentPeriodStart) {
            $meta['remote_period_start'] = $remoteSub->currentPeriodStart->toDateTimeString();
        }
        $subscription->remote_metadata = $meta;
        $subscription->last_synced_at = now();
        $subscription->save();

        // Payment confirmed — activate locally
        $paymentMethod = $invoice->customer->paymentMethods()->create([
            'payment_gateway_id' => $paymentGateway->id,
            'autobilling_data' => json_encode([]),
            'can_auto_charge' => false,
        ]);

        $invoice->paySuccess($paymentMethod);

        return response()->json([
            'success' => true,
            'redirect_url' => Billing::getReturnUrl() ?: url('/'),
        ]);
    }
}
