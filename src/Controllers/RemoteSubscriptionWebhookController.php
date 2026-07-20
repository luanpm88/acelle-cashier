<?php

namespace App\Cashier\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Model\Subscription;
use App\Model\PaymentGateway;
use App\Model\Setting;
use App\Library\Facades\Billing;
use App\Cashier\Services\StripeGateway;
use App\Cashier\Contracts\ManageRemoteSubscriptionInterface;
use Illuminate\Support\Facades\Log;

class RemoteSubscriptionWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // Dev/test switch: acknowledge every Stripe webhook with 200 and do NOTHING when
        // develop.disable_stripe_webhook = 'yes'. Lets us test the webhook-independent poll
        // (checkRemoteCheckoutIntent) in isolation, without the webhook racing to complete
        // the checkout first. Short-circuits before signature verification / any handling.
        if (Setting::get('develop.disable_stripe_webhook') === 'yes') {
            // Surface the dropped event as a WARNING in the affected customer's bell so it's
            // visible (not a silent log). Resolve the customer via the subscription_uid we
            // stamp into the session/subscription metadata (getCheckoutUrl). Parsing
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

        // Resolve WHICH Stripe gateway this event belongs to. There is ONE global webhook URL
        // (routes.php) but there can be MULTIPLE active `type=stripe` gateways — two Stripe
        // accounts, test+live, or a per-customer gateway alongside the global one
        // (payment_gateways.type has NO unique constraint; the 2026-07-04 collapse migration
        // explicitly leaves two 'stripe' rows coexisting). Each row has its OWN webhook signing
        // secret, so we can't grab "the first" — that either verifies the wrong account's
        // signature (400 → Stripe retries forever → event lost) or calls the wrong Stripe API
        // key downstream. We find the gateway whose secret actually verifies THIS signature; that
        // verified gateway IS the account that signed the event, so every downstream
        // getRemoteSubscription() then uses the correct API key too.
        $gateways = PaymentGateway::where('type', StripeGateway::TYPE)->active()->get();

        if ($gateways->isEmpty()) {
            Log::warning('Stripe subscription webhook received but no active gateway configured');
            return response()->json(['status' => 'no_gateway'], 200);
        }

        // HINT (untrusted): getCheckoutUrl() stamps gateway_uid into the checkout session +
        // subscription metadata, so most events carry it on data.object.metadata. Read it WITHOUT
        // trusting it — it only orders which secret we try FIRST, so the common single-account
        // case verifies in one attempt. A spoofed or absent hint simply falls through to trying
        // the remaining gateways; trust comes SOLELY from the signature verify below. Absent on
        // invoice.* (an invoice does not inherit its subscription's metadata) → hint is null there.
        $raw     = $request->getContent();
        $hintUid = json_decode($raw, true)['data']['object']['metadata']['gateway_uid'] ?? null;
        [$hinted, $rest] = $gateways->partition(fn ($g) => $g->uid === $hintUid);

        $gateway = null;
        $parsed  = null;

        foreach ($hinted->concat($rest) as $candidate) {
            $service = Billing::resolveService($candidate);
            if (!($service instanceof ManageRemoteSubscriptionInterface)) {
                continue;
            }

            try {
                $parsed  = $service->parseWebhookPayload($raw, $request->headers->all());
                $gateway = $candidate; // secret matched → this is the account that signed the event
                break;
            } catch (\Stripe\Exception\SignatureVerificationException $e) {
                // Wrong secret for THIS gateway (or event outside Stripe's replay tolerance).
                // Try the next active gateway — do NOT swallow: if none verifies we 400 below.
                continue;
            } catch (\UnexpectedValueException $e) {
                // Malformed JSON body: every gateway would fail identically, so stop now.
                Log::error('Stripe subscription webhook: malformed payload: ' . $e->getMessage());
                return response()->json(['error' => trans('cashier::messages.webhook.invalid_signature')], 400);
            }
        }

        if (!$gateway) {
            // No active Stripe gateway's secret verified this signature: a spoofed event, a stale
            // delivery, or an account we don't have configured. 400 → Stripe retries then gives up
            // (correct — we never process an unauthenticated event).
            Log::error('Stripe subscription webhook: signature did not verify against any active Stripe gateway');
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

        // data.object is a DIFFERENT resource per event family, so data.id is the
        // subscription id ONLY for customer.subscription.* — for invoice.* it's the
        // invoice id (in_…). Resolve per-event so the by-id lookup below can match.
        $remoteSubId = $this->resolveRemoteSubscriptionId($event, $data);

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

        try {
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
        } catch (\Throwable $e) {
            // A handler failed (e.g. a transient Stripe API error inside
            // getRemoteSubscription). Return 500 so Stripe RETRIES the delivery rather
            // than acknowledging 200 and losing the event — a lost customer.subscription.deleted
            // leaves the sub active after cancellation; a lost invoice.paid never advances the
            // period. Mirrors handleRemoteCheckoutCompleted's 500-on-failure. The handlers are
            // idempotent (isNew/isActive guards), so a retried delivery is safe.
            Log::error("Webhook handler failed for {$event} (subscription {$subscription->uid}); returning 500 for Stripe retry", [
                'error' => $e->getMessage(),
            ]);
            return response()->json(['status' => 'handler_failed'], 500);
        }

        return response()->json(['status' => 'processed'], 200);
    }

    /**
     * Resolve the REMOTE (Stripe) subscription id for a webhook event so the by-id
     * lookup in handleWebhookEvent() can find the local Subscription.
     *
     * `data.object` is a different resource per event family, so `data.id` is the
     * subscription id ONLY for customer.subscription.* :
     *
     *   - customer.subscription.*  → data.object IS the Subscription → id = "sub_…" = data.id.
     *   - invoice.*                → data.object is the Invoice       → data.id = "in_…" (the
     *     INVOICE id, NOT the subscription). Under the pinned API version "2026-06-24.dahlia"
     *     the invoice→subscription field moved off the top-level `invoice.subscription` onto
     *     `invoice.parent.subscription_details.subscription`, discriminated by
     *     `invoice.parent.type === 'subscription_details'` — the SAME extraction as the
     *     object-form StripeGateway::stripeInvoiceToDto(). We read the dahlia path first, then
     *     fall back to the legacy pre-basil top-level `invoice.subscription` for any older payload.
     *
     * Returns null (never a wrong id) for a one-off / manual / quote invoice that has no
     * subscription, so the caller logs no_sub_id / unknown_subscription and 200s instead of
     * mis-matching another row (this is the prod bug: "invoice.paid for unknown remote
     * subscription: in_…"). Webhook payloads carry a plain "sub_…" STRING (Stripe never expands
     * sub-objects in webhooks); we also tolerate an expanded object (array carrying 'id').
     */
    private function resolveRemoteSubscriptionId(string $event, array $data): ?string
    {
        // customer.subscription.created / .updated / .deleted / … → data.object IS the Subscription.
        if (str_starts_with($event, 'customer.subscription.')) {
            return $data['id'] ?? null;
        }

        // invoice.paid / invoice.payment_failed / any invoice.* → data.object is the Invoice.
        if (str_starts_with($event, 'invoice.')) {
            // dahlia: invoice.subscription removed → invoice.parent.subscription_details.subscription
            // (parent.type is the discriminator; only 'subscription_details' carries a subscription).
            $ref = (($data['parent']['type'] ?? null) === 'subscription_details')
                ? ($data['parent']['subscription_details']['subscription'] ?? null)
                : null;

            // Legacy (pre-basil) fallback: top-level invoice.subscription.
            $ref = $ref ?? ($data['subscription'] ?? null);

            // Webhooks send a plain "sub_…" string; tolerate an expanded object (array with 'id').
            if (is_array($ref)) {
                $ref = $ref['id'] ?? null;
            }

            return (is_string($ref) && $ref !== '') ? $ref : null;
        }

        // Any other event (checkout.session.completed is already routed above): no subscription
        // id on data.id → null lets the caller log no_sub_id and 200 rather than mis-look-up.
        return null;
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
        // its cs_xxx (getCheckoutUrl → remote_reference_id), so the preferred
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
        // No local try/catch: a failure (e.g. getRemoteSubscription) propagates to
        // handleWebhookEvent's boundary catch → 500 → Stripe retries. Do NOT re-add a
        // swallow-and-return here — that acks 200 and loses the sync.
        $service = Billing::resolveService($gateway);
        $remoteSub = $service->getRemoteSubscription($subscription->remote_subscription_id);

        if ($subscription->isNew() && ($remoteSub->isActive() || $remoteSub->isTrialing())) {
            app(\App\Services\Subscription\SubscriptionManagementService::class)->activateFromRemote($subscription, $gateway);
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
    }

    protected function handleSubscriptionDeleted(Subscription $subscription, array $data)
    {
        // No local try/catch: failure propagates to the boundary catch → 500 → Stripe retries.
        // A swallowed error here would leave the sub ACTIVE after Stripe cancelled it.
        if ($subscription->isActive()) {
            app(\App\Services\Subscription\SubscriptionManagementService::class)->cancelNow($subscription);
            Log::info("Subscription {$subscription->uid} cancelled via webhook (remote subscription deleted)");
        }
    }

    protected function handleInvoicePaid(Subscription $subscription, array $data, PaymentGateway $gateway)
    {
        // No local try/catch: failure propagates to the boundary catch → 500 → Stripe retries
        // (a swallowed error would drop a renewal and never advance current_period_ends_at).
        $service = Billing::resolveService($gateway);
        $remoteSub = $service->getRemoteSubscription($subscription->remote_subscription_id);

        if ($subscription->isNew() && ($remoteSub->isActive() || $remoteSub->isTrialing())) {
            app(\App\Services\Subscription\SubscriptionManagementService::class)->activateFromRemote($subscription, $gateway);
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

        // ── APP-INITIATED pending plan change now PAID → flip local plan_id to the recorded target. ──
        // When the buyer pays a held (pending_if_incomplete) upgrade on Stripe's hosted invoice page,
        // Stripe applies the pending_update: the sub's CURRENT remote plan becomes the target. The local
        // flip was deliberately deferred to HERE — initiateRemotePlanChange only RECORDED the intended
        // target (pending_change_plan_id) and never flipped, so entitlement is granted only on payment.
        // This is NOT a silent remote heal (which the sync guard rightly refuses): we flip ONLY to the
        // plan the APP itself recorded, and ONLY once Stripe confirms the switch actually applied.
        // Idempotent: the plan_id != target guard skips a re-delivered webhook; the marker is cleared.
        $pendingPlanId = $meta['pending_change_plan_id'] ?? null;
        if ($pendingPlanId) {
            $targetPlan         = \App\Model\Plan::find((int) $pendingPlanId);
            $targetRemotePlanId = $targetPlan?->remoteSubscriptionItem()?->remote_plan_id;
            if ($targetPlan && $targetRemotePlanId
                && $remoteSub->remotePlanId === $targetRemotePlanId
                && (int) $subscription->plan_id !== (int) $targetPlan->id
            ) {
                $oldPlan = $subscription->plan;
                // $endsAt = null: a remote sub's period is gateway truth, already stamped on $subscription
                // above (current_period_ends_at from $remoteSub->currentPeriodEnd) — applyPlanChangeState
                // only needs to write plan_id here. (Passing the DTO's Carbon\Carbon would also fail the
                // ?Illuminate\Support\Carbon type hint.)
                app(\App\Services\Subscription\SubscriptionManagementService::class)
                    ->applyPlanChangeState($subscription, $targetPlan, null);
                app(\App\Services\Plans\Lifecycle\SubscriptionLifecycle::class)->onChangePlan($subscription);
                unset($meta['pending_change_plan_id']);
                Log::info("Pending plan change applied via webhook: sub {$subscription->uid} → plan #{$targetPlan->id}");
                \Illuminate\Support\Facades\DB::afterCommit(function () use ($subscription, $oldPlan, $targetPlan) {
                    \App\Library\Facades\Hook::fire('plan_changed', [$subscription->customer, $oldPlan, $targetPlan]);
                });
            }
        }

        $subscription->remote_metadata = $meta;
        $subscription->last_synced_at = now();
        $subscription->save();

        Log::info("Invoice paid webhook processed for subscription {$subscription->uid}");
    }

    protected function handlePaymentFailed(Subscription $subscription, array $data)
    {
        Log::warning("Payment failed for subscription {$subscription->uid} (remote: {$subscription->remote_subscription_id})");
    }
}
