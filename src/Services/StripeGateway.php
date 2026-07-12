<?php

namespace App\Cashier\Services;

use App\Cashier\Contracts\IntentGatewayInterface;
use App\Cashier\Contracts\SupportsAutoChargeInterface;
use App\Cashier\Contracts\SupportsRemoteHostedCheckout;
use App\Cashier\Contracts\SupportsBundledItems;
use App\Cashier\Contracts\SupportsRemoteOneTimePriceCatalogInterface;
use App\Cashier\Contracts\ManageRemoteSubscriptionInterface;
use App\Cashier\Contracts\SupportsRemoteCatalogInterface;
use App\Cashier\Contracts\SupportsDiscounts;
use App\Cashier\DTO\DiscountSpec;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\PaymentResult;
use App\Cashier\DTO\PaymentMethodDTO;
use App\Cashier\DTO\BillingOrigin;
use App\Cashier\DTO\CheckoutHandle;
use App\Cashier\DTO\PollableCheckout;
use App\Cashier\DTO\RemoteCheckoutSessionDTO;
use App\Cashier\DTO\RemoteInvoiceDTO;
use App\Cashier\DTO\RemotePlanChangePreviewDTO;
use App\Cashier\DTO\RemotePlanDTO;
use App\Cashier\DTO\RemoteOneTimePriceDTO;
use App\Cashier\DTO\RemoteSubscriptionDTO;
use App\Cashier\DTO\RemoteBillingDetailsDTO;
use Carbon\Carbon;

/**
 * Stripe gateway — ONE driver for the whole Stripe account (one-off + subscription).
 *
 * One vendor = one driver: `type` is the vendor (`stripe`), never vendor×mode. Capability is
 * the set of interfaces this class implements (single source of truth), not a hand-declared flag:
 * - IntentGatewayInterface                          → on-site one-off checkout (getCheckoutUrl)
 * - SupportsAutoChargeInterface                     → off-session charge of a saved card
 * - SupportsRemoteHostedCheckout  → hosted Checkout Session (one-off up-front + trialing sub)
 * - SupportsBundledItems                            → bundle one-offs alongside the sub
 * - SupportsRemoteOneTimePriceCatalogInterface      → Stripe's one_time price catalog
 * - ManageRemoteSubscriptionInterface               → read/sync/cancel/resume/webhook
 * - SupportsRemoteCatalogInterface                  → plans, list-subs, invoice history
 * - SupportsDiscounts                               → apply a DiscountSpec (→ a Stripe Coupon)
 *
 * Pure: no DB writes, no handler callbacks. Controllers orchestrate side-effects.
 */
class StripeGateway implements
    IntentGatewayInterface,
    SupportsAutoChargeInterface,
    SupportsRemoteHostedCheckout,
    SupportsBundledItems,
    SupportsRemoteOneTimePriceCatalogInterface,
    ManageRemoteSubscriptionInterface,
    SupportsRemoteCatalogInterface,
    SupportsDiscounts
{
    public const TYPE = 'stripe';

    protected string $publishableKey;
    protected string $secretKey;
    protected ?string $webhookSecret;
    protected bool $active = false;

    public function __construct(string $publishableKey, string $secretKey, ?string $webhookSecret = null)
    {
        $this->publishableKey = $publishableKey;
        $this->secretKey      = $secretKey;
        $this->webhookSecret  = $webhookSecret;

        if ($publishableKey && $secretKey) {
            $this->active = true;
            \Stripe\Stripe::setApiKey($this->secretKey);
            // One unified API version for the whole account (one-off + subscription).
            // Pinned to 2026-06-24.dahlia (was 2023-10-16, ~3 release trains behind). dahlia
            // relocated subscription current_period_* to the ITEM level and moved
            // invoice.subscription / invoice.payment_intent under invoice.parent / invoice.payments
            // — the mapping methods below read the new paths. SDK stays on stripe-php v7.128
            // (StripeObject's dynamic field access reads the relocated fields; setApiVersion only
            // sets the Stripe-Version header — verified live against dahlia).
            \Stripe\Stripe::setApiVersion('2026-06-24.dahlia');
            // Idempotent auto-retry of transient failures: the SDK reuses ONE idempotency key
            // across retries of a single request, so an off-session charge whose response was
            // lost is retried idempotently (closes the double-charge window). NOT a per-intent
            // key — the 3DS re-auth flow re-charges the SAME intent and must stay a fresh attempt.
            \Stripe\Stripe::setMaxNetworkRetries(2);
        }
    }

    public function validate(): void
    {
        $this->active = (bool) ($this->publishableKey && $this->secretKey);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getPublishableKey(): string
    {
        return $this->publishableKey;
    }

    public function getSecretKey(): string
    {
        return $this->secretKey;
    }

    public function getWebhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    // ── IntentGatewayInterface — the single checkout entry (ALWAYS a hosted Stripe Checkout Session) ──

    /**
     * Stripe ALWAYS uses its hosted Checkout page — there is no on-site card form. Build one
     * Checkout Session from the PaymentIntent and return a {@see PollableCheckout} carrying the
     * `cs_xxx` session id so the host can poll completion without a webhook:
     *  - `$intent->subscription !== null` → `mode:subscription` (provider-managed recurring; charges
     *    any one-off line items up-front + starts the trialing subscription).
     *  - else → `mode:payment` + `setup_future_usage:off_session` (app-handled one-off: charge now
     *    AND vault the card so off-session `autoCharge` renewals work later — the host persists the
     *    card at session-reconcile; see getCheckoutSession + the host completeCheckoutIntent).
     * Routing ids ride `$intent->metadata` (echoed for webhook attribution). Pure: no DB writes.
     */
    public function getCheckoutUrl(PaymentIntent $intent, string $returnUrl, ?string $cancelUrl = null): CheckoutHandle
    {
        // Canonical Stripe customer for this payer (same cus_ the saved-card path uses), pre-filled
        // so a first-time buyer's hosted page arrives with email/address populated.
        $customer = $this->resolveStripeCustomer($intent->payer->uid, $intent->payer->name);
        $this->prefillCustomerBilling($customer, $intent->payer);

        $params = [
            'customer'                   => $customer->id,
            // name => never: a cardholder name (possibly a company/spouse card) must not overwrite
            // our billing name. address => auto: pre-fill the country on the card field.
            'customer_update'            => ['name' => 'never', 'address' => 'auto'],
            'billing_address_collection' => 'auto',
            // The app owns its return URL (it already carries the invoice handle it needs on
            // return). We do NOT inject Stripe's {CHECKOUT_SESSION_ID}: the app reconciles the
            // browser return by its own invoice reference, never by a gateway session id.
            'success_url'                => $returnUrl,
            'cancel_url'                 => $cancelUrl ?? $returnUrl,
            'client_reference_id'        => $intent->uid,
            'metadata'                   => $intent->metadata,
        ];

        $sub = $intent->subscription;
        if ($sub !== null) {
            // Provider-managed subscription (+ bundled one-offs charged up-front).
            $lineItems = [['price' => $sub->remotePlanId, 'quantity' => 1]];
            foreach ($sub->oneTimePriceIds as $oneTimePriceId) {
                $lineItems[] = ['price' => $oneTimePriceId, 'quantity' => 1];
            }
            $params['mode']                      = 'subscription';
            $params['line_items']                = $lineItems;
            $params['payment_method_collection'] = 'always';
            $params['subscription_data']         = array_filter([
                'trial_period_days' => $sub->trialDays,
                'metadata'          => $intent->metadata ?: null,
            ]);
            // Explicitly select Stripe's flexible billing engine (dahlia's default since clover
            // 2025-09-30): item-level billing periods + more precise proration/credits. Not yet in
            // production, so there is no classic fleet to preserve — adopt the modern engine
            // outright. Declared explicitly (not left to the default) so a future Stripe default
            // change can't flip it. current_period is item-level here — subscriptionPeriod() reads it.
            $params['subscription_data']['billing_mode'] = ['type' => 'flexible'];
        } else {
            // App-handled one-off: charge the invoice amount now AND vault the card
            // (setup_future_usage) so the local subscription's off-session autoCharge renewals reuse it.
            $params['mode']                = 'payment';
            $params['line_items']          = [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => strtolower($intent->currency),
                    'unit_amount'  => $this->convertPrice($intent->amount, $intent->currency),
                    'product_data' => ['name' => $intent->description],
                ],
            ]];
            $params['payment_intent_data'] = ['setup_future_usage' => 'off_session'];
        }

        // SupportsDiscounts — realise the gateway-NEUTRAL DiscountSpec into Stripe's own
        // mechanism (a Coupon on the session). `duration:once` means only the FIRST charge is
        // discounted; in the trial bundle the recurring is $0 today (trial-deferred), so the cut
        // lands on the up-front license line. Works for both mode:subscription and mode:payment.
        if ($intent->discount !== null) {
            $params['discounts'] = [['coupon' => $this->ensureStripeCoupon($intent->discount)]];
        }

        $session = \Stripe\Checkout\Session::create($params);

        return new PollableCheckout(
            url:       $session->url,
            sessionId: $session->id,
            expiresAt: $session->expires_at,
        );
    }

    /**
     * Map a neutral {@see DiscountSpec} to a reusable Stripe Coupon, returning its id. Keeps ALL
     * Stripe-specifics (the Coupon object, ids, `duration`) inside this driver — the app/DTO never
     * name a `co_…`. The id is DETERMINISTIC per (type,value,currency) so every app code of the same
     * value reuses ONE Coupon (never one-per-code): retrieve it, and create it once on 404. Idempotent
     * against a create/create race (Stripe returns the existing resource → treat as success).
     */
    private function ensureStripeCoupon(DiscountSpec $discount): string
    {
        $id = $this->couponId($discount);

        try {
            \Stripe\Coupon::retrieve($id);

            return $id;
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Only "does not exist" is a create-it signal; anything else is a real error → rethrow.
            if ($e->getStripeCode() !== 'resource_missing') {
                throw $e;
            }
        }

        $params = ['id' => $id, 'duration' => 'once'];   // once = the up-front charge only (not recurring)
        if ($discount->type === DiscountSpec::TYPE_FIXED) {
            $params['amount_off'] = $this->convertPrice($discount->value, $discount->currency);
            $params['currency']   = strtolower((string) $discount->currency);
        } else {
            $params['percent_off'] = $discount->value;
        }

        try {
            \Stripe\Coupon::create($params);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // ONLY a create/create race is OK to ignore: a concurrent request already created this
            // id, so Stripe returns `resource_already_exists` and the coupon we wanted now exists.
            // ANY other failure (bad params, auth, rate-limit, …) is real → rethrow it UNCHANGED so
            // its true cause surfaces — never mask it behind a follow-up retrieve (retrieve THROWS
            // on missing, it never returns null, so that check was dead code that hid the real error).
            if ($e->getStripeCode() !== 'resource_already_exists') {
                throw $e;
            }
        }

        return $id;
    }

    /**
     * Deterministic, Stripe-legal coupon id for a discount value (alphanumeric + underscore; '.'
     * → '_' so 12.5% stays a valid id). `match` throws on an unknown type — no fall-through.
     */
    private function couponId(DiscountSpec $discount): string
    {
        $value = rtrim(rtrim(number_format($discount->value, 2, '.', ''), '0'), '.');
        $value = str_replace('.', '_', $value);

        return match ($discount->type) {
            DiscountSpec::TYPE_PERCENT => "disc_pct_{$value}",
            DiscountSpec::TYPE_FIXED   => 'disc_fix_' . strtolower((string) $discount->currency) . "_{$value}",
            default                    => throw new \InvalidArgumentException("Unknown discount type: {$discount->type}"),
        };
    }

    // ── SupportsAutoChargeInterface — off-session charge of a saved card (PURE) ───────

    public function autoCharge(PaymentIntent $intent, PaymentMethodDTO $pm): PaymentResult
    {
        // Remote-subscription intent → create the PROVIDER subscription off-session with the saved
        // card (same recurring mechanism as the mode:subscription hosted lane, but no redirect).
        // Returns STATUS_SUBSCRIPTION_CREATED carrying the RemoteSubscriptionDTO so the host links it
        // via onSubscriptionCreated. A one-off intent falls through to the amount charge below.
        if ($intent->subscription !== null) {
            return $this->autoChargeSubscription($intent, $pm);
        }

        // An off-session PaymentIntent can't attach a Stripe Coupon (that's a Checkout Session
        // feature — see getCheckoutUrl above), so a carried discount is realized by charging the
        // NET amount directly (the DTO subtracts the app-resolved coupon_amount = the recorded
        // invoice.discount_amount; no discount → full amount).
        $chargeAmount = $intent->netAmountAfterDiscount();

        // Free invoice OR a discount that zeroes it out: skip Stripe (it rejects a $0 off-session
        // charge). Caller dispatches success; the redemption still commits from the stamped metadata.
        if ($chargeAmount <= 0) {
            return PaymentResult::success('FREE_NO_CHARGE');
        }

        try {
            $pi = \Stripe\PaymentIntent::create([
                'amount'         => $this->convertPrice($chargeAmount, $intent->currency),
                'currency'       => strtolower($intent->currency),
                'customer'       => $pm->remoteCustomerId,
                'payment_method' => $pm->remotePaymentMethodId,
                'off_session'    => true,
                'confirm'        => true,
                'description'    => $intent->description,
                'metadata'       => ['intent_uid' => $intent->uid],
            ]);

            if ($pi->status === 'succeeded') {
                return PaymentResult::success($pi->id);
            }

            if ($pi->status === 'requires_action') {
                return PaymentResult::requiresAuth(clientSecret: $pi->client_secret, remoteRef: $pi->id);
            }

            return PaymentResult::failed("Unexpected PaymentIntent status: {$pi->status}", $pi->id);
        } catch (\Stripe\Exception\CardException $e) {
            $err      = $e->getError();
            $remotePi = $err->payment_intent ?? null;

            // Off-session authentication: the saved card needs 3DS, which can't run with the
            // customer absent. Stripe signals this TWO ways depending on timing — for a fresh
            // off-session confirm the decline code is 'authentication_required' and the PI
            // REVERTS to requires_payment_method (NOT requires_action, verified live); for an
            // already-pending PI the status is requires_action. Either case is NOT a hard
            // decline — surface requiresAuth with the client secret so the caller can re-prompt
            // the customer on-session, instead of failing the charge outright.
            $needsAuth = ($err->code ?? null) === 'authentication_required'
                || ($remotePi->status ?? null) === 'requires_action';

            if ($needsAuth && $remotePi) {
                return PaymentResult::requiresAuth(clientSecret: $remotePi->client_secret, remoteRef: $remotePi->id);
            }

            return PaymentResult::failed($e->getMessage(), $remotePi?->id);
        } catch (\Throwable $e) {
            return PaymentResult::failed($e->getMessage());
        }
    }

    /**
     * Off-session sibling of the mode:subscription hosted lane (getCheckoutUrl): create the provider
     * subscription with the SAVED card, no redirect. Same shape — recurring plan in `items`, bundled
     * one-offs in `add_invoice_items`, trial, and the discount as a Stripe Coupon (duration:once, NOT
     * a net subtraction — a subscription's cut must land on the up-front line, per COUPON.md).
     *
     * Off-session outcome is read from the SUBSCRIPTION status — the pinned API (dahlia 2026-06-24)
     * removed `invoice.payment_intent`, so we never touch it:
     *  - active / trialing → SUBSCRIPTION_CREATED (host links via onSubscriptionCreated).
     *  - anything else (incomplete/past_due/…) → the first charge did NOT settle off-session (a decline,
     *    or a card that needs SCA which can't run with the customer absent). Cancel the dangling sub and
     *    report failure — the customer completes via the hosted checkout lane instead (never leave a
     *    half-created subscription, never a silent overcharge).
     */
    private function autoChargeSubscription(PaymentIntent $intent, PaymentMethodDTO $pm): PaymentResult
    {
        $spec = $intent->subscription;

        try {
            $params = [
                'customer'               => $pm->remoteCustomerId,
                'items'                  => [['price' => $spec->remotePlanId, 'quantity' => 1]],
                'default_payment_method' => $pm->remotePaymentMethodId,
                'off_session'            => true,           // attempt the first invoice now, customer absent
                'expand'                 => ['latest_invoice'],
                'metadata'               => $intent->metadata ?: ['intent_uid' => $intent->uid],
                // Match the hosted lane: modern flexible billing engine (item-level periods).
                'billing_mode'           => ['type' => 'flexible'],
            ];
            if (! empty($spec->oneTimePriceIds)) {
                $params['add_invoice_items'] = array_map(
                    fn ($priceId) => ['price' => $priceId],
                    $spec->oneTimePriceIds
                );
            }
            if ($spec->trialDays !== null) {
                $params['trial_period_days'] = $spec->trialDays;
            }
            // SupportsDiscounts — a subscription realises the discount as a Stripe Coupon (duration:once),
            // exactly like getCheckoutUrl. NOT netAmountAfterDiscount (that is the one-off amount path).
            if ($intent->discount !== null) {
                $params['discounts'] = [['coupon' => $this->ensureStripeCoupon($intent->discount)]];
            }

            $sub = \Stripe\Subscription::create($params);

            // Active/trialing → the first invoice settled off-session (or is trial-deferred). Live.
            if (in_array($sub->status, ['active', 'trialing'], true)) {
                return PaymentResult::subscriptionCreated($this->mapSubscriptionToDto($sub));
            }

            // First charge did not settle off-session (decline / SCA-required). Don't leave a
            // half-created incomplete subscription behind.
            $this->cancelSubscriptionQuietly($sub->id);
            $liStatus = ($sub->latest_invoice && is_object($sub->latest_invoice)) ? $sub->latest_invoice->status : 'unknown';
            return PaymentResult::failed(
                "Off-session subscription first charge did not settle (subscription: {$sub->status}, invoice: {$liStatus}) — complete via checkout.",
                $sub->id
            );
        } catch (\Stripe\Exception\CardException $e) {
            // Off-session decline (incl. authentication_required). No subscription to clean up — Stripe
            // rejects the create before the sub exists on a hard card error.
            return PaymentResult::failed($e->getMessage());
        } catch (\Throwable $e) {
            return PaymentResult::failed($e->getMessage());
        }
    }

    /** Best-effort IMMEDIATE cancel of a just-created incomplete subscription (cleanup on a failed
     *  first charge). Instance ->cancel() (not the removed static Subscription::cancel) so it works on
     *  the pinned SDK; distinct from cancelRemoteSubscription() which schedules cancel_at_period_end. */
    private function cancelSubscriptionQuietly(string $subscriptionId): void
    {
        try {
            \Stripe\Subscription::retrieve($subscriptionId)->cancel();
        } catch (\Throwable $e) {
            // Cleanup only — Stripe auto-expires an incomplete subscription; nothing to surface.
        }
    }

    // ── SupportsRemoteCatalogInterface — recurring price catalog ──────────────────────

    public function getRemotePlans(): array
    {
        $prices = \Stripe\Price::all([
            'active' => true,
            'type'   => 'recurring',
            'limit'  => 100,
            'expand' => ['data.product'],
        ]);

        return array_map(fn ($price) => $this->mapPriceToPlanDto($price), $prices->data);
    }

    public function getRemotePlan(string $remotePlanId): RemotePlanDTO
    {
        $price = \Stripe\Price::retrieve(['id' => $remotePlanId, 'expand' => ['product']]);
        return $this->mapPriceToPlanDto($price);
    }

    private function mapPriceToPlanDto(\Stripe\Price $price): RemotePlanDTO
    {
        $product = $price->product;
        $trialDays = $price->recurring->trial_period_days ?? null;

        return new RemotePlanDTO(
            id:            $price->id,
            name:          is_object($product) ? $product->name : $price->id,
            // revertPrice, not /100 — zero/three-decimal currencies (VND, JPY, KWD…) have
            // a different minor-unit rate (previewPlanChange already did this correctly).
            price:         $this->revertPrice($price->unit_amount, strtoupper($price->currency)),
            currency:      strtoupper($price->currency),
            intervalCount: $price->recurring->interval_count,
            intervalUnit:  $price->recurring->interval,
            status:        $price->active ? 'active' : 'inactive',
            trialDays:     $trialDays ? (int) $trialDays : null,
            metadata: [
                'stripe_product_id' => is_object($product) ? $product->id : $price->product,
                'stripe_price_id'   => $price->id,
            ],
        );
    }

    // ── SupportsRemoteOneTimePriceCatalogInterface — one_time price catalog ───────────

    public function getRemoteOneTimePrices(): array
    {
        $prices = \Stripe\Price::all([
            'active' => true,
            'type'   => 'one_time',
            'limit'  => 100,
            'expand' => ['data.product'],
        ]);

        return array_map(fn ($price) => $this->mapPriceToOneTimeDto($price), $prices->data);
    }

    public function getRemoteOneTimePrice(string $remotePriceId): RemoteOneTimePriceDTO
    {
        $price = \Stripe\Price::retrieve(['id' => $remotePriceId, 'expand' => ['product']]);

        if ($price->type !== 'one_time' || $price->recurring !== null) {
            throw new \InvalidArgumentException("Price {$remotePriceId} is not a one_time price.");
        }

        return $this->mapPriceToOneTimeDto($price);
    }

    private function mapPriceToOneTimeDto(\Stripe\Price $price): RemoteOneTimePriceDTO
    {
        $product = $price->product;

        return new RemoteOneTimePriceDTO(
            id:        $price->id,
            name:      is_object($product) ? $product->name : $price->id,
            price:     $this->revertPrice($price->unit_amount, strtoupper($price->currency)),
            currency:  strtoupper($price->currency),
            status:    $price->active ? 'active' : 'inactive',
            productId: is_object($product) ? $product->id : (string) $price->product,
            metadata: [
                'stripe_product_id' => is_object($product) ? $product->id : $price->product,
                'stripe_price_id'   => $price->id,
            ],
        );
    }

    // ── SupportsRemoteHostedCheckout — webhook-independent poll readback (+ prefill helper) ──

    /**
     * Sync the payer's email / name / billing address onto the RESOLVED Stripe customer so the
     * hosted Checkout form arrives pre-filled (a fresh customer is name-only otherwise). Only
     * writes fields that differ.
     *
     * NO try/catch — fail loud. A failure here is a real bug (malformed params / bad API key),
     * not a tolerable degrade: a transient Stripe outage would fail Session::create immediately
     * after this anyway, so swallowing wouldn't save the sale — it would only HIDE the bug and
     * silently ship a customer with no billing pre-filled. Let it propagate.
     */
    private function prefillCustomerBilling(\Stripe\Customer $customer, \App\Cashier\DTO\Payer $payer): void
    {
        $update = [];
        if ($payer->email !== '' && $customer->email !== $payer->email) {
            $update['email'] = $payer->email;
        }
        $name = $payer->billingName !== '' ? $payer->billingName : $payer->name;
        if ($name !== '' && $customer->name !== $name) {
            $update['name'] = $name;
        }
        if ($payer->billingCountryCode !== '') {
            $update['address'] = array_filter([
                'line1'   => $payer->billingAddress ?: null,
                'country' => $payer->billingCountryCode,
            ]);
        }
        if ($payer->phone !== '') {
            $update['phone'] = $payer->phone;
        }
        if ($update) {
            \Stripe\Customer::update($customer->id, $update);
        }
    }

    /**
     * Read back a Checkout Session by id — the webhook-independent poll. Throws
     * \Stripe\Exception\InvalidRequestException for an unknown/expired-deleted id.
     *  - mode:subscription → carry remoteSubscriptionId + period end (from the expanded subscription).
     *  - mode:payment (app-handled one-off) → carry paymentIntentId (pi_…) + a PaymentMethodDTO (from
     *    the expanded payment_intent.payment_method) so the host can vault the card at reconcile.
     */
    public function getCheckoutSession(string $sessionId): RemoteCheckoutSessionDTO
    {
        $s = \Stripe\Checkout\Session::retrieve([
            'id'     => $sessionId,
            'expand' => ['subscription', 'payment_intent.payment_method'],
        ]);

        $customer         = $s->customer;
        $remoteCustomerId = is_object($customer) ? $customer->id : (is_string($customer) ? $customer : null);

        $billing = $s->customer_details
            ? RemoteBillingDetailsDTO::fromStripeCustomerDetails($s->customer_details)
            : null;

        $sub         = $s->subscription;
        $remoteSubId = is_object($sub) ? $sub->id : (is_string($sub) ? $sub : null);
        // dahlia: the billing period is item-level now (see subscriptionPeriod()).
        $periodEnd   = is_object($sub) ? $this->subscriptionPeriod($sub)['end'] : null;

        // mode:payment one-off — pull the charge id + the card to vault for off-session renewals.
        $paymentIntentId = null;
        $paymentMethod   = null;
        if ($s->mode === 'payment') {
            $pi              = $s->payment_intent;
            $paymentIntentId = is_object($pi) ? $pi->id : (is_string($pi) ? $pi : null);
            $pm              = is_object($pi) ? ($pi->payment_method ?? null) : null;
            if (is_object($pm) && ($pm->card ?? null)) {
                $paymentMethod = new PaymentMethodDTO(
                    cardType:              ucfirst((string) $pm->card->brand),
                    last4:                 (string) $pm->card->last4,
                    expirationDate:        $pm->card->exp_month . '/' . $pm->card->exp_year,
                    email:                 null,
                    type:                  'card',
                    remotePaymentMethodId: $pm->id,
                    remoteCustomerId:      $remoteCustomerId,
                    expMonth:              (int) $pm->card->exp_month,
                    expYear:               (int) $pm->card->exp_year,
                    autoCharge:            true,
                );
            }
        }

        return new RemoteCheckoutSessionDTO(
            id:                   $s->id,
            status:               $s->status,
            paymentStatus:        $s->payment_status,
            remoteSubscriptionId: $remoteSubId,
            remoteCustomerId:     $remoteCustomerId,
            currentPeriodEnd:     $periodEnd,
            billingDetails:       $billing,
            paymentIntentId:      $paymentIntentId,
            paymentMethod:        $paymentMethod,
            // Exact settled amounts (cents) — the authoritative discount + charge for the invoice
            // record, so it never relies on a locally rounded coupon_amount. total_details is present
            // once the session completes; absent on an open session → null (caller falls back).
            amountSubtotal:       $s->amount_subtotal,
            amountDiscount:       $s->total_details?->amount_discount,
            amountTotal:          $s->amount_total,
        );
    }

    // ── ManageRemoteSubscriptionInterface — read/sync ────────────────────────────────

    public function getRemoteSubscription(string $remoteSubscriptionId): RemoteSubscriptionDTO
    {
        $sub = \Stripe\Subscription::retrieve(['id' => $remoteSubscriptionId, 'expand' => ['latest_invoice']]);
        return $this->mapSubscriptionToDto($sub);
    }

    public function getRemoteSubscriptions(?string $startingAfter = null, int $limit = 100): array
    {
        $params = [
            'limit'  => max(1, min($limit, 100)),
            'status' => 'all',
            'expand' => ['data.latest_invoice'],
        ];
        if ($startingAfter) {
            $params['starting_after'] = $startingAfter;
        }

        $page = \Stripe\Subscription::all($params);
        $data = array_map(fn ($sub) => $this->mapSubscriptionToDto($sub), $page->data);

        return [
            'data'        => $data,
            'has_more'    => (bool) $page->has_more,
            'next_cursor' => $page->has_more && count($data) > 0 ? end($data)->id : null,
        ];
    }

    /**
     * Subscription billing period — dahlia relocated it to the ITEM level (2025-03-31.basil
     * removed subscription-level current_period_start/end). A single-price subscription carries
     * it on items.data[0]; a mixed-interval subscription's whole-sub window is [max(start),
     * min(end)] across items, per Stripe's own rule. Returns ['start'=>?Carbon, 'end'=>?Carbon]
     * (null when no item carries a period, e.g. an incomplete subscription — DTO fields nullable).
     */
    private function subscriptionPeriod(\Stripe\Subscription $sub): array
    {
        $startTs = null;
        $endTs   = null;
        foreach (($sub->items->data ?? []) as $item) {
            $s = $item->current_period_start ?? null;
            $e = $item->current_period_end ?? null;
            if ($s !== null) { $startTs = $startTs === null ? $s : max($startTs, $s); }
            if ($e !== null) { $endTs   = $endTs === null ? $e : min($endTs, $e); }
        }
        return [
            'start' => $startTs !== null ? Carbon::createFromTimestamp($startTs) : null,
            'end'   => $endTs !== null ? Carbon::createFromTimestamp($endTs) : null,
        ];
    }

    private function mapSubscriptionToDto(\Stripe\Subscription $sub): RemoteSubscriptionDTO
    {
        $period = $this->subscriptionPeriod($sub);
        $latestAmount = null;
        $latestStatus = null;
        $latestId     = null;
        if ($sub->latest_invoice) {
            if (is_object($sub->latest_invoice)) {
                $latestAmount = $this->revertPrice(
                    $sub->latest_invoice->amount_paid,
                    strtoupper((string) $sub->latest_invoice->currency)
                );
                $latestStatus = $sub->latest_invoice->status;
                $latestId     = $sub->latest_invoice->id;
            } else {
                $latestId = $sub->latest_invoice; // unexpanded → the invoice id string
            }
        }

        return new RemoteSubscriptionDTO(
            id:                  $sub->id,
            status:              $sub->status,
            remotePlanId:        $sub->items->data[0]->price->id ?? '',
            remoteCustomerId:    is_string($sub->customer) ? $sub->customer : ($sub->customer->id ?? null),
            currentPeriodEnd:    $period['end'],
            currentPeriodStart:  $period['start'],
            canceledAt:          $sub->canceled_at ? Carbon::createFromTimestamp($sub->canceled_at) : null,
            latestInvoiceAmount: $latestAmount,
            latestInvoiceStatus: $latestStatus,
            latestInvoiceId:     $latestId,
            metadata:            ['stripe_subscription' => $sub->toArray()],
        );
    }

    public function getRemotePaymentMethod(string $remoteSubscriptionId): ?PaymentMethodDTO
    {
        $sub = \Stripe\Subscription::retrieve($remoteSubscriptionId);

        $paymentMethodId = $sub->default_payment_method;
        if (is_object($paymentMethodId) && isset($paymentMethodId->id)) {
            $paymentMethodId = $paymentMethodId->id;
        }
        if (!$paymentMethodId || !is_string($paymentMethodId)) {
            $customer = \Stripe\Customer::retrieve($sub->customer);
            $paymentMethodId = $customer->invoice_settings->default_payment_method ?? null;
            if (is_object($paymentMethodId) && isset($paymentMethodId->id)) {
                $paymentMethodId = $paymentMethodId->id;
            }
        }

        if (!$paymentMethodId || !is_string($paymentMethodId)) {
            return null;
        }

        $pm = \Stripe\PaymentMethod::retrieve($paymentMethodId);
        $customerId = is_object($sub->customer) ? ($sub->customer->id ?? null) : $sub->customer;

        if ($pm->type === 'card' && $pm->card) {
            return new PaymentMethodDTO(
                cardType:              ucfirst($pm->card->brand),
                last4:                 $pm->card->last4,
                expirationDate:        $pm->card->exp_month . '/' . $pm->card->exp_year,
                email:                 null,
                type:                  'card',
                remotePaymentMethodId: $paymentMethodId,
                remoteCustomerId:      $customerId,
                expMonth:              (int) $pm->card->exp_month,
                expYear:               (int) $pm->card->exp_year,
                autoCharge:            true,
            );
        }

        return new PaymentMethodDTO(
            cardType:              null,
            last4:                 null,
            expirationDate:        null,
            email:                 null,
            type:                  $pm->type,
            remotePaymentMethodId: $paymentMethodId,
            remoteCustomerId:      $customerId,
            autoCharge:            true,
        );
    }

    public function cancelRemoteSubscription(string $remoteSubscriptionId): void
    {
        \Stripe\Subscription::update($remoteSubscriptionId, ['cancel_at_period_end' => true]);
    }

    public function resumeRemoteSubscription(string $remoteSubscriptionId): void
    {
        \Stripe\Subscription::update($remoteSubscriptionId, ['cancel_at_period_end' => false]);
    }

    public function updateRemoteSubscriptionPlan(string $remoteSubscriptionId, string $newRemotePlanId, bool $chargeImmediately = false, ?int $prorationDate = null): RemoteSubscriptionDTO
    {
        $sub = \Stripe\Subscription::retrieve($remoteSubscriptionId);

        $params = [
            'items'              => [['id' => $sub->items->data[0]->id, 'price' => $newRemotePlanId]],
            // Default: defer the price-difference proration to the next invoice (no charge now).
            // Charge-immediately: `always_invoice` creates + attempts the proration invoice right now.
            'proration_behavior' => $chargeImmediately ? 'always_invoice' : 'create_prorations',
        ];

        // Pin the proration to the SAME instant a preview used, so the charge matches the quoted
        // amount to the cent (proration is per-second — the seconds between quote and charge drift it).
        if ($prorationDate !== null) {
            $params['proration_date'] = $prorationDate;
        }

        // Charge-immediately on a TRIALING sub: end the trial now, else the invoice collects nothing
        // (a trial bills $0). Only when trialing — `trial_end:'now'` on a non-trial sub is rejected by Stripe.
        if ($chargeImmediately && $sub->status === 'trialing') {
            $params['trial_end'] = 'now';
        }

        \Stripe\Subscription::update($remoteSubscriptionId, $params);

        return $this->getRemoteSubscription($remoteSubscriptionId);
    }

    public function previewPlanChange(string $remoteSubscriptionId, string $newRemotePlanId, ?int $prorationDate = null): RemotePlanChangePreviewDTO
    {
        // Default the pinned instant to now; the caller re-uses this exact value when it charges.
        $prorationDate = $prorationDate ?? time();

        $sub = \Stripe\Subscription::retrieve($remoteSubscriptionId);

        $subscriptionDetails = [
            'items'              => [['id' => $sub->items->data[0]->id, 'price' => $newRemotePlanId]],
            'proration_behavior' => 'always_invoice',
            'proration_date'     => $prorationDate,
        ];

        // Mirror updateRemoteSubscriptionPlan's charge path EXACTLY: on a TRIALING sub the immediate
        // charge ends the trial now (trial_end:'now'), which bills the FULL new-plan amount. The preview
        // MUST end the trial too — otherwise it quotes $0 (a trial bills nothing) while the real charge
        // bills the full amount, so the confirm screen would promise "0" and then charge the customer.
        // Only when trialing — Stripe rejects trial_end on a non-trial subscription.
        if ($sub->status === 'trialing') {
            $subscriptionDetails['trial_end'] = 'now';
        }

        // Preview WITHOUT creating an invoice or charging. The account runs the dahlia API version,
        // where /invoices/upcoming is removed in favour of /invoices/create_preview; stripe-php v7 has
        // no helper for it, so issue the raw request — it inherits the globally-set dahlia Stripe-Version
        // (this class's constructor). `always_invoice` + the trial_end mirror above make the returned
        // `total` equal what updateRemoteSubscriptionPlan(chargeImmediately: true) will bill — provided
        // the SAME proration_date is passed back to it (proration is per-second).
        $requestor = new \Stripe\ApiRequestor(\Stripe\Stripe::getApiKey());
        [$response] = $requestor->request('post', '/v1/invoices/create_preview', [
            'customer'             => $sub->customer,
            'subscription'         => $remoteSubscriptionId,
            'subscription_details' => $subscriptionDetails,
        ]);
        $data = $response->json;

        // Minor units → major units via the canonical converter (handles zero/two/three-decimal
        // currencies) — the SAME helper getRemoteInvoice() uses, never a hardcoded /100.
        $currency = strtoupper($data['currency'] ?? 'usd');

        return new RemotePlanChangePreviewDTO(
            amount: (float) $this->revertPrice((int) ($data['total'] ?? 0), $currency),
            currency: $currency,
            prorationDate: $prorationDate,
        );
    }

    public function parseWebhookPayload(string $payload, array $headers): array
    {
        $sigHeader = $headers['stripe-signature'] ?? ($headers['Stripe-Signature'] ?? '');
        // Laravel/Symfony's $request->headers->all() returns each header as a LIST of
        // values ($sigHeader === ['t=…,v1=…']); Stripe's constructEvent()/WebhookSignature
        // needs the single header STRING, else explode() throws a TypeError — a \Error,
        // not \Exception, so it escapes the webhook controller's catch and 500s instead
        // of returning a clean 400 invalid_signature.
        if (is_array($sigHeader)) {
            $sigHeader = $sigHeader[0] ?? '';
        }
        $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);

        return [
            'event'     => $event->type,
            'data'      => $event->data->object->toArray(),
            'raw_event' => $event,
        ];
    }

    /**
     * List ALL Stripe Invoices for a subscription, oldest-first (full history each time;
     * caller dedups on remote_invoice_id). $afterId/$limit accepted for interface
     * compatibility but ignored — a newer-than-X cursor can silently skip imported history.
     */
    public function getRemoteInvoices(string $remoteSubscriptionId, ?string $afterId = null, int $limit = 50): array
    {
        // The `subscription` LIST FILTER survives on dahlia (only the invoice.subscription FIELD
        // moved to invoice.parent). Dropped the old `data.payment_intent.payment_method` expand:
        // invoice.payment_intent is gone, and payments→payment_intent→payment_method exceeds
        // Stripe's 4-level expand limit on a LIST — so per-invoice card fields degrade to null
        // (see stripeInvoiceToDto). Subscription id comes off invoice.parent (default-returned).
        $invoices = \Stripe\Invoice::all([
            'subscription' => $remoteSubscriptionId,
            'limit'        => 100,
        ], ['api_key' => $this->secretKey]);

        $all = [];
        foreach ($invoices->autoPagingIterator() as $inv) {
            $all[] = $inv;
        }
        usort($all, fn ($a, $b) => $a->created <=> $b->created);

        $data = [];
        foreach ($all as $inv) {
            $dto = $this->stripeInvoiceToDto($inv);
            if ($dto !== null) {
                $data[] = $dto;
            }
        }

        return ['data' => $data, 'has_more' => false, 'next_cursor' => null];
    }

    /**
     * Fetch ONE invoice by id. A direct retrieve reads Stripe's primary store — it is immediately
     * consistent, unlike the LIST endpoint (getRemoteInvoices) whose index lags a few seconds behind
     * creation. Used to materialize a just-created invoice synchronously (e.g. an upgrade's
     * charge-immediately proration). Returns null if the payload can't map to an invoice DTO.
     */
    public function getRemoteInvoice(string $invoiceId): ?RemoteInvoiceDTO
    {
        $inv = \Stripe\Invoice::retrieve($invoiceId, ['api_key' => $this->secretKey]);

        return $this->stripeInvoiceToDto($inv);
    }

    private function stripeInvoiceToDto(\Stripe\Invoice $inv): ?RemoteInvoiceDTO
    {
        if ($inv->status === 'draft') {
            return null;
        }

        $origin = match ($inv->billing_reason) {
            'subscription_create'    => BillingOrigin::INITIAL,
            'subscription_cycle'     => BillingOrigin::RECURRING,
            'subscription_update'    => BillingOrigin::PLAN_CHANGE,
            'subscription_threshold' => BillingOrigin::PLAN_CHANGE,
            'manual'                 => BillingOrigin::MANUAL,
            default                  => BillingOrigin::MANUAL,
        };

        $status = match ($inv->status) {
            'paid'           => 'paid',
            'open'           => 'open',
            'uncollectible',
            'void'           => 'failed',
            default          => 'open',
        };

        $line = $inv->lines->data[0] ?? null;
        $period = $line && isset($line->period) ? $line->period : null;

        // dahlia: invoice.payment_intent was removed; the paying charge is now under
        // invoice.payments[].payment (payment.type === 'payment_intent'). The card PM is not
        // reachable within Stripe's 4-level expand limit on a LIST, so per-invoice card fields
        // degrade to null in history sync (the CURRENT card is served by getRemotePaymentMethod);
        // we still surface the card when a caller expanded payments.data.payment.payment_intent
        // into an object on a single retrieve.
        $pmRemoteId = null;
        $pmBrand    = null;
        $pmLast4    = null;
        foreach (($inv->payments->data ?? []) as $payment) {
            $charge = $payment->payment ?? null;
            if (!is_object($charge) || ($charge->type ?? null) !== 'payment_intent') {
                continue;
            }
            $pi = $charge->payment_intent ?? null;
            if (is_object($pi)) {
                $pm = $pi->payment_method ?? null;
                if (is_object($pm)) {
                    $pmRemoteId = $pm->id ?? null;
                    $card = $pm->card ?? null;
                    if (is_object($card)) {
                        $pmBrand = ucfirst((string) ($card->brand ?? ''));
                        $pmLast4 = (string) ($card->last4 ?? '');
                    }
                } elseif (is_string($pm)) {
                    $pmRemoteId = $pm;
                }
            }
            break; // the default payment_intent payment
        }

        // dahlia: invoice.subscription removed → invoice.parent.subscription_details.subscription
        // (parent + its ids are returned by default; parent.type is the discriminator).
        $remoteSubId = ($inv->parent && ($inv->parent->type ?? null) === 'subscription_details')
            ? ($inv->parent->subscription_details->subscription ?? null)
            : null;

        return new RemoteInvoiceDTO(
            id:                   (string) $inv->id,
            remoteSubscriptionId: (string) $remoteSubId,
            origin:               $origin,
            status:               $status,
            amount:               (float) $this->revertPrice((int) ($inv->amount_paid ?? 0), strtoupper((string) $inv->currency)),
            currency:             strtoupper((string) $inv->currency),
            periodStart:          $period ? Carbon::createFromTimestamp($period->start) : null,
            periodEnd:            $period ? Carbon::createFromTimestamp($period->end)   : null,
            billedAt:             Carbon::createFromTimestamp($inv->created),
            failureReason:        $inv->last_finalization_error->message ?? null,
            hostedInvoiceUrl:     $inv->hosted_invoice_url ?? $inv->invoice_pdf,
            paymentMethodRemoteId: $pmRemoteId,
            paymentMethodBrand:    $pmBrand ?: null,
            paymentMethodLast4:    $pmLast4 ?: null,
            billingDetails:        RemoteBillingDetailsDTO::fromStripeInvoice($inv),
        );
    }

    // ── Customer + card helpers (one canonical convention: metadata['payer_uid'] + search) ──

    /** Find a Stripe Customer by local payer uid (search; no auto-create). */
    public function getStripeCustomer(string $payerUid): ?\Stripe\Customer
    {
        $customers = \Stripe\Customer::search([
            'query' => "metadata['payer_uid']:'{$payerUid}'",
        ]);

        return $customers->data[0] ?? null;
    }

    public function createStripeCustomer(string $payerUid, string $name = ''): \Stripe\Customer
    {
        $params = ['metadata' => ['payer_uid' => $payerUid]];
        if ($name) {
            $params['name'] = $name;
        }
        return \Stripe\Customer::create($params);
    }

    /** The Stripe Customer for a payer, creating one if none exists yet. */
    public function resolveStripeCustomer(string $payerUid, string $name = ''): \Stripe\Customer
    {
        return $this->getStripeCustomer($payerUid) ?? $this->createStripeCustomer($payerUid, $name);
    }

    public function hasCard(string $payerUid): bool
    {
        return is_object($this->getCardInformation($payerUid));
    }

    public function getCardInformation(string $payerUid)
    {
        $customer = $this->getStripeCustomer($payerUid);
        if (!$customer) {
            return null;
        }

        $cards = \Stripe\PaymentMethod::all(['customer' => $customer->id, 'type' => 'card']);
        return empty($cards->data) ? null : $cards->data[0];
    }

    /** SetupIntent client_secret for stripe.confirmCardSetup() in JS. */
    public function getClientSecret(string $payerUid): string
    {
        $customer = $this->resolveStripeCustomer($payerUid);

        $intent = \Stripe\SetupIntent::create([
            'customer' => $customer->id,
            'usage'    => 'off_session',
        ]);

        return $intent->client_secret;
    }

    public function getPaymentMethod(string $paymentMethodId)
    {
        return \Stripe\PaymentMethod::retrieve($paymentMethodId);
    }

    // ── Pricing helpers (Stripe wants the smallest currency unit; zero-decimal exceptions) ──

    /**
     * Minor-units-per-major-unit by currency, mirroring Stripe's OWN spec (not ISO 4217 —
     * Stripe deviates: e.g. UGX/ISK are ISO-zero-decimal but Stripe wants them as two-decimal
     * with a trailing 00, so they correctly default to 100 here). Hardcoded ON PURPOSE:
     * Stripe exposes NO API for this spec (stripe-java#874 asked and was declined) — the doc
     * is the source of truth and every integration pins it.
     * Verified against https://docs.stripe.com/currencies on 2026-07-11. Default (absent) = 100.
     */
    public function currencyRates(): array
    {
        return [
            // Zero-decimal (charge amount == major units).
            'BIF' => 1, 'CLP' => 1, 'DJF' => 1, 'GNF' => 1, 'JPY' => 1,
            'KMF' => 1, 'KRW' => 1, 'MGA' => 1, 'PYG' => 1, 'RWF' => 1,
            'VND' => 1, 'VUV' => 1, 'XAF' => 1, 'XOF' => 1, 'XPF' => 1,
            // Three-decimal (amount in thousandths; Stripe additionally requires the last
            // digit to be 0 — convertPrice() rounds to the nearest ten to satisfy it).
            'BHD' => 1000, 'IQD' => 1000, 'JOD' => 1000, 'KWD' => 1000,
            'LYD' => 1000, 'OMR' => 1000, 'TND' => 1000,
        ];
    }

    public function convertPrice($price, $currency)
    {
        $rate = $this->currencyRates()[$currency] ?? 100;
        $amount = (int) round($price * $rate);

        // Three-decimal currencies settle to two decimals: Stripe rejects a minor-unit
        // amount whose last digit isn't 0 (e.g. KWD 5.124 → 5124 invalid, must be 5120).
        if ($rate === 1000) {
            $amount = (int) (round($amount / 10) * 10);
        }

        return $amount;
    }

    public function revertPrice($price, $currency)
    {
        $rate = $this->currencyRates()[$currency] ?? 100;
        return $price / $rate;
    }

    public function getMinimumChargeAmount($currency): int
    {
        return 0;
    }

    public function supportsAutoBilling(): bool
    {
        return true;
    }

    /** Health check on credentials. */
    public function test(): void
    {
        try {
            \Stripe\Customer::all(['limit' => 1]);
        } catch (\Throwable $e) {
            throw new \Exception($e->getMessage());
        }
    }
}
