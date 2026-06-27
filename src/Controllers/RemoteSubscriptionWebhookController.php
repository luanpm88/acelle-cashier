<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Model\Subscription;
use App\Model\PaymentGateway;
use App\Model\Setting;
use App\Cashier\Services\StripeSubscriptionGateway;
use App\Cashier\Contracts\ManageRemoteSubscriptionInterface;
use Illuminate\Support\Facades\Log;

class RemoteSubscriptionWebhookController extends Controller
{
    public function stripeSubscription(Request $request)
    {
        // Dev/test switch: acknowledge every Stripe webhook with 200 and do NOTHING when
        // develop.disable_stripe_webhook = 'yes'. Lets us test the webhook-independent poll
        // (checkRemoteCheckoutIntent) in isolation, without the webhook racing to complete
        // the checkout first. Short-circuits before signature verification / any handling.
        if (Setting::get('develop.disable_stripe_webhook') === 'yes') {
            // Surface the dropped event as a WARNING in the affected customer's bell so it's
            // visible (not a silent log). Resolve the customer via the subscription_uid we
            // stamp into the session/subscription metadata (buildRemoteCheckoutUrl). Parsing
            // raw JSON (no signature verify) is fine — this is a no-op dev path.
            $payload   = json_decode($request->getContent(), true) ?: [];
            $eventType = $payload['type'] ?? 'unknown';
            $subUid    = $payload['data']['object']['metadata']['subscription_uid'] ?? null;
            $subscription = $subUid ? Subscription::where('uid', $subUid)->first() : null;

            if ($subscription && $subscription->customer) {
                app(\App\Services\Notifications\Notifier::class)->dispatch(
                    new \App\Notifications\Customer\StripeWebhookSuppressed($subscription, $eventType),
                    $subscription->customer
                );
            } else {
                // Couldn't attribute the event to a local customer — keep a trace.
                Log::info('Stripe webhook suppressed (develop.disable_stripe_webhook=yes) but no local subscription resolved', [
                    'event'           => $eventType,
                    'subscription_uid' => $subUid,
                ]);
            }

            return response()->json(['status' => 'webhook_disabled'], 200);
        }

        $gateway = PaymentGateway::where('type', StripeSubscriptionGateway::TYPE)->active()->first();

        if (!$gateway) {
            Log::warning('Stripe subscription webhook received but no active gateway found');
            return response()->json(['status' => 'no_gateway'], 200);
        }

        $service = $gateway->getService();
        if (!($service instanceof ManageRemoteSubscriptionInterface)) {
            return response()->json(['status' => 'invalid_service'], 400);
        }

        try {
            $parsed = $service->parseWebhookPayload(
                $request->getContent(),
                $request->headers->all()
            );
        } catch (\Exception $e) {
            Log::error('Stripe subscription webhook signature verification failed: ' . $e->getMessage());
            return response()->json(['error' => trans('cashier::messages.webhook.invalid_signature')], 400);
        }

        return $this->handleWebhookEvent($parsed, $gateway);
    }

    protected function handleWebhookEvent(array $parsed, PaymentGateway $gateway)
    {
        $event = $parsed['event'];
        $data = $parsed['data'];

        // A hosted REMOTE CHECKOUT checkout (Stripe Checkout subscription-mode) completed —
        // routed BEFORE the by-id subscription lookup below, because its `data.id`
        // is a checkout-session id (cs_…), not a subscription id. The Stripe sub is
        // on `data.subscription`.
        if ($event === 'checkout.session.completed') {
            return $this->handleRemoteCheckoutCompleted($data, $gateway);
        }

        $remoteSubId = $data['id'] ?? null;

        if (!$remoteSubId) {
            Log::warning("Webhook event {$event} has no subscription ID");
            return response()->json(['status' => 'no_sub_id'], 200);
        }

        $subscription = Subscription::where('remote_subscription_id', $remoteSubId)->first();

        if (!$subscription) {
            Log::info("Webhook event {$event} for unknown remote subscription: {$remoteSubId}");
            return response()->json(['status' => 'unknown_subscription'], 200);
        }

        Log::info("Processing webhook event {$event} for subscription {$subscription->uid} (remote: {$remoteSubId})");

        switch ($event) {
            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($subscription, $data, $gateway);
                break;

            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($subscription, $data);
                break;

            case 'invoice.paid':
                $this->handleInvoicePaid($subscription, $data, $gateway);
                break;

            case 'invoice.payment_failed':
                $this->handlePaymentFailed($subscription, $data);
                break;

            default:
                Log::info("Unhandled webhook event: {$event}");
        }

        return response()->json(['status' => 'processed'], 200);
    }

    /**
     * A hosted remote checkout completed: Stripe has charged the one-off items
     * up-front and created the (trialing) subscription. Signature is already
     * verified by parseWebhookPayload(). Only sessions WE created carry our
     * metadata (plan_id / gateway_uid); others are ignored.
     *
     * If the local Subscription was pre-created and tagged (metadata.subscription_uid),
     * we link the new remote subscription id to it here. Creating a local mirror
     * from scratch (Stripe-first, no pre-created sub) + granting the one-off item
     * entitlements is the remaining sync-back step — intentionally not a blind
     * write; it depends on the local subscription/entitlement model.
     */
    protected function handleRemoteCheckoutCompleted(array $data, PaymentGateway $gateway)
    {
        $mode        = $data['mode'] ?? null;
        $meta        = $data['metadata'] ?? [];
        $remoteSubId = $data['subscription'] ?? null;

        if ($mode !== 'subscription' || empty($meta['plan_id'])) {
            Log::info('checkout.session.completed ignored (not a remote checkout session we created)', [
                'session' => $data['id'] ?? null,
                'mode'    => $mode,
            ]);
            return response()->json(['status' => 'ignored'], 200);
        }

        Log::info('Remote-checkout checkout completed', [
            'session'        => $data['id'] ?? null,
            'remote_sub_id'  => $remoteSubId,
            'customer'       => $data['customer'] ?? null,
            'customer_email' => $data['customer_details']['email'] ?? ($data['customer_email'] ?? null),
            'amount_total'   => $data['amount_total'] ?? null,
            'currency'       => $data['currency'] ?? null,
            'plan_id'        => $meta['plan_id'],
            'payment_status' => $data['payment_status'] ?? null,
        ]);

        $sessionId = $data['id'] ?? null;

        // PREFERRED: route through the local PaymentIntent that carries this session's
        // cs_xxx handle (the same row Acelle polls). Completing via the SHARED
        // onSubscriptionCreated means webhook + poller + browser-return converge on one
        // path. Webhook here is just a fast-path; the poller is the durable mechanism.
        $intent = $sessionId
            ? \App\Model\PaymentIntent::where('remote_reference_id', $sessionId)->first()
            : null;

        if ($remoteSubId && $intent) {
            // Converge on the SAME locked, atomic, idempotent completion the proactive poller
            // uses: checkRemoteCheckoutIntent locks the intent (lockForUpdate) + re-checks
            // isPending UNDER the lock + runs completeCheckoutIntent → the shared
            // onSubscriptionCreated. This SERIALIZES webhook vs poll vs browser-return (no
            // duplicate PaymentMethod, no double-complete) and makes the whole completion atomic.
            // The webhook is just the fast trigger; the poller is the durable mechanism. Never
            // throw a fatal out un-caught (a 500 makes Stripe retry forever) — but a transient
            // failure SHOULD 500 so Stripe retries.
            try {
                $outcome = app(\App\Services\Subscription\SubscriptionManagementService::class)
                    ->checkRemoteCheckoutIntent($intent);

                return response()->json(['status' => "remote_checkout_{$outcome}"], 200);
            } catch (\Throwable $e) {
                Log::error('Remote-checkout webhook: completion failed, Stripe will retry', [
                    'intent' => $intent->uid,
                    'error'  => $e->getMessage(),
                ]);
                return response()->json(['status' => 'remote_checkout_failed'], 500); // 500 → Stripe retries
            }
        }

        // Every checkout session we create carries a local PaymentIntent tagged with
        // its cs_xxx (buildRemoteCheckoutUrl → remote_reference_id), so the preferred
        // branch above is the ONLY live completion path. Reaching here means the session
        // isn't one of ours (or its intent vanished) — surface it, but don't make Stripe
        // retry forever (a 500 would loop).
        Log::warning('Remote-checkout webhook: no local PaymentIntent for completed session', [
            'session'       => $sessionId,
            'remote_sub_id' => $remoteSubId,
        ]);

        return response()->json(['status' => 'no_local_intent_for_session'], 200);
    }

    protected function handleSubscriptionUpdated(Subscription $subscription, array $data, PaymentGateway $gateway)
    {
        try {
            $service = $gateway->getService();
            $remoteSub = $service->getRemoteSubscription($subscription->remote_subscription_id);

            if ($subscription->isNew() && ($remoteSub->isActive() || $remoteSub->isTrialing())) {
                $subscription->activateFromRemote($gateway);
            }

            if ($remoteSub->currentPeriodEnd && $subscription->isActive()) {
                $subscription->current_period_ends_at = $remoteSub->currentPeriodEnd;
            }

            $meta = $subscription->getRemoteMetadataArray();
            $meta['remote_status'] = $remoteSub->status;
            if ($remoteSub->remotePlanId) {
                $meta['remote_plan_id'] = $remoteSub->remotePlanId;
            }
            if ($remoteSub->currentPeriodEnd) {
                $meta['remote_period_end'] = $remoteSub->currentPeriodEnd->toDateTimeString();
            }
            $subscription->remote_metadata = $meta;
            $subscription->last_synced_at = now();
            $subscription->save();

            Log::info("Subscription {$subscription->uid} updated from webhook. Remote status: {$remoteSub->status}");
        } catch (\Exception $e) {
            Log::error("Error handling subscription updated webhook for {$subscription->uid}: " . $e->getMessage());
        }
    }

    protected function handleSubscriptionDeleted(Subscription $subscription, array $data)
    {
        try {
            if ($subscription->isActive()) {
                $subscription->cancelNow();
                Log::info("Subscription {$subscription->uid} cancelled via webhook (remote subscription deleted)");
            }
        } catch (\Exception $e) {
            Log::error("Error handling subscription deleted webhook for {$subscription->uid}: " . $e->getMessage());
        }
    }

    protected function handleInvoicePaid(Subscription $subscription, array $data, PaymentGateway $gateway)
    {
        try {
            $service = $gateway->getService();
            $remoteSub = $service->getRemoteSubscription($subscription->remote_subscription_id);

            if ($subscription->isNew() && ($remoteSub->isActive() || $remoteSub->isTrialing())) {
                $subscription->activateFromRemote($gateway);
            }

            if ($remoteSub->currentPeriodEnd && $subscription->isActive()) {
                $subscription->current_period_ends_at = $remoteSub->currentPeriodEnd;
            }

            $meta = $subscription->getRemoteMetadataArray();
            $meta['remote_status'] = $remoteSub->status;
            if ($remoteSub->latestInvoiceAmount !== null) {
                $meta['latest_invoice_amount'] = $remoteSub->latestInvoiceAmount;
                $meta['latest_invoice_status'] = $remoteSub->latestInvoiceStatus;
            }
            $subscription->remote_metadata = $meta;
            $subscription->last_synced_at = now();
            $subscription->save();

            Log::info("Invoice paid webhook processed for subscription {$subscription->uid}");
        } catch (\Exception $e) {
            Log::error("Error handling invoice paid webhook for {$subscription->uid}: " . $e->getMessage());
        }
    }

    protected function handlePaymentFailed(Subscription $subscription, array $data)
    {
        Log::warning("Payment failed for subscription {$subscription->uid} (remote: {$subscription->remote_subscription_id})");
    }
}
