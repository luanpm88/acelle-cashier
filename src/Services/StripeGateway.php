<?php

namespace App\Cashier\Services;

use App\Cashier\Contracts\IntentGatewayInterface;
use App\Cashier\Contracts\SupportsAutoChargeInterface;
use App\Cashier\Contracts\SupportsRemoteHostedCheckout;
use App\Cashier\Contracts\SupportsBundledItems;
use App\Cashier\Contracts\SupportsRemoteOneTimePriceCatalogInterface;
use App\Cashier\Contracts\ManageRemoteSubscriptionInterface;
use App\Cashier\Contracts\SupportsRemoteCatalogInterface;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\PaymentResult;
use App\Cashier\DTO\PaymentMethodDTO;
use App\Cashier\DTO\BillingOrigin;
use App\Cashier\DTO\RemoteCheckoutHandle;
use App\Cashier\DTO\RemoteCheckoutSessionDTO;
use App\Cashier\DTO\RemoteInvoiceDTO;
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
    SupportsRemoteCatalogInterface
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
            \Stripe\Stripe::setApiVersion('2023-10-16');
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

    // ── IntentGatewayInterface — on-site one-off checkout ────────────────────────────

    public function getCheckoutUrl(PaymentIntent $intent, string $returnUrl): string
    {
        return action('\App\Cashier\Controllers\StripeController@checkout', [
            'intent_uid' => $intent->uid,
        ]) . '?return_url=' . urlencode($returnUrl);
    }

    // ── SupportsAutoChargeInterface — off-session charge of a saved card (PURE) ───────

    public function autoCharge(PaymentIntent $intent, PaymentMethodDTO $pm): PaymentResult
    {
        // Free invoice: skip Stripe. Caller dispatches success.
        if ($intent->amount <= 0) {
            return PaymentResult::success('FREE_NO_CHARGE');
        }

        try {
            $pi = \Stripe\PaymentIntent::create([
                'amount'         => $this->convertPrice($intent->amount, $intent->currency),
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
            price:         $price->unit_amount / 100,
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
            price:     $price->unit_amount / 100,
            currency:  strtoupper($price->currency),
            status:    $price->active ? 'active' : 'inactive',
            productId: is_object($product) ? $product->id : (string) $price->product,
            metadata: [
                'stripe_product_id' => is_object($product) ? $product->id : $price->product,
                'stripe_price_id'   => $price->id,
            ],
        );
    }

    // ── SupportsRemoteHostedCheckout — hosted Checkout Session ──────

    /**
     * Build one Stripe Checkout Session (subscription mode) that charges the one-off items
     * up-front AND starts the (trialing) subscription — one card entry. Reads everything from
     * the PaymentIntent: pricing from `$intent->subscription`, the buyer from `$intent->payer`,
     * and the host routing ids from `$intent->metadata` (echoed into the session metadata for
     * webhook attribution). Returns a handle carrying the `cs_xxx` session id so the host can
     * poll completion without a webhook.
     */
    public function buildRemoteCheckoutUrl(PaymentIntent $intent, string $returnUrl): RemoteCheckoutHandle
    {
        $sub = $intent->subscription;
        if ($sub === null) {
            throw new \LogicException("PaymentIntent {$intent->uid} has no subscription spec — cannot build a hosted checkout.");
        }

        $lineItems = [['price' => $sub->remotePlanId, 'quantity' => 1]];
        foreach ($sub->oneTimePriceIds as $oneTimePriceId) {
            $lineItems[] = ['price' => $oneTimePriceId, 'quantity' => 1];
        }

        // Attach the subscription to the canonical Stripe customer for this payer (the same
        // cus_ one-off charges use — Phase A customer convention), creating it if missing.
        $customer = $this->resolveStripeCustomer($intent->payer->uid, $intent->payer->name);

        // PRE-FILL: the payer DTO already carries the buyer's email + billing address (from
        // the invoice), but a freshly-created customer is name-only — so a FIRST-TIME buyer's
        // hosted checkout arrives with an empty email/address (returning customers pre-fill
        // only because Stripe saved their email on a PRIOR checkout). Propagate it now, on the
        // RESOLVED customer (by id — no second Customer::search, so no duplicate-customer race).
        $this->prefillCustomerBilling($customer, $intent->payer);

        $params = [
            'mode'       => 'subscription',
            'line_items' => $lineItems,
            'payment_method_collection'  => 'always',
            'billing_address_collection' => 'required',
            // Pre-fill name + address from the (just-synced) customer and persist buyer edits.
            // (Email pre-fills from customer.email automatically — no customer_update key for it.)
            'customer_update' => ['name' => 'auto', 'address' => 'auto'],
            'success_url' => $this->appendQueryParam($returnUrl, 'session_id', '{CHECKOUT_SESSION_ID}'),
            'cancel_url'  => $returnUrl,
            'customer'    => $customer->id,
            'client_reference_id' => $intent->uid,
            'metadata'    => $intent->metadata,
            'subscription_data' => array_filter([
                'trial_period_days' => $sub->trialDays,
                'metadata'          => $intent->metadata ?: null,
            ]),
        ];

        $session = \Stripe\Checkout\Session::create($params);

        return new RemoteCheckoutHandle(
            url:       $session->url,
            sessionId: $session->id,
            expiresAt: $session->expires_at,
        );
    }

    /**
     * Sync the payer's email / name / billing address onto the RESOLVED Stripe customer so
     * the hosted Checkout form arrives pre-filled (a fresh customer is name-only otherwise).
     * Only writes fields that differ. Pre-fill is cosmetic, so a Stripe API failure here must
     * NOT block the sale — it is logged and the checkout proceeds with whatever the customer
     * already has. A non-Stripe error is NOT swallowed (it propagates).
     */
    private function prefillCustomerBilling(\Stripe\Customer $customer, \App\Cashier\DTO\Payer $payer): void
    {
        try {
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
        } catch (\Stripe\Exception\ApiErrorException $e) {
            \Illuminate\Support\Facades\Log::warning('StripeGateway: customer pre-fill sync failed (non-fatal, checkout proceeds)', [
                'customer' => $customer->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Read back a Checkout Session by id, expanding the subscription it created. Throws
     * \Stripe\Exception\InvalidRequestException for an unknown/expired-deleted id.
     */
    public function getCheckoutSession(string $sessionId): RemoteCheckoutSessionDTO
    {
        $s = \Stripe\Checkout\Session::retrieve(['id' => $sessionId, 'expand' => ['subscription']]);

        $sub         = $s->subscription;
        $remoteSubId = is_object($sub) ? $sub->id : (is_string($sub) ? $sub : null);
        $periodEnd   = (is_object($sub) && isset($sub->current_period_end))
            ? Carbon::createFromTimestamp($sub->current_period_end)
            : null;

        $customer         = $s->customer;
        $remoteCustomerId = is_object($customer) ? $customer->id : (is_string($customer) ? $customer : null);

        $billing = $s->customer_details
            ? RemoteBillingDetailsDTO::fromStripeCustomerDetails($s->customer_details)
            : null;

        return new RemoteCheckoutSessionDTO(
            id:                   $s->id,
            status:               $s->status,
            paymentStatus:        $s->payment_status,
            remoteSubscriptionId: $remoteSubId,
            remoteCustomerId:     $remoteCustomerId,
            currentPeriodEnd:     $periodEnd,
            billingDetails:       $billing,
        );
    }

    private function appendQueryParam(string $url, string $key, string $value): string
    {
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . $key . '=' . $value;
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

    private function mapSubscriptionToDto(\Stripe\Subscription $sub): RemoteSubscriptionDTO
    {
        $latestAmount = null;
        $latestStatus = null;
        if ($sub->latest_invoice && is_object($sub->latest_invoice)) {
            $latestAmount = $sub->latest_invoice->amount_paid / 100;
            $latestStatus = $sub->latest_invoice->status;
        }

        return new RemoteSubscriptionDTO(
            id:                  $sub->id,
            status:              $sub->status,
            remotePlanId:        $sub->items->data[0]->price->id ?? '',
            remoteCustomerId:    is_string($sub->customer) ? $sub->customer : ($sub->customer->id ?? null),
            currentPeriodEnd:    Carbon::createFromTimestamp($sub->current_period_end),
            currentPeriodStart:  Carbon::createFromTimestamp($sub->current_period_start),
            canceledAt:          $sub->canceled_at ? Carbon::createFromTimestamp($sub->canceled_at) : null,
            latestInvoiceAmount: $latestAmount,
            latestInvoiceStatus: $latestStatus,
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

    public function updateRemoteSubscriptionPlan(string $remoteSubscriptionId, string $newRemotePlanId): RemoteSubscriptionDTO
    {
        $sub = \Stripe\Subscription::retrieve($remoteSubscriptionId);

        \Stripe\Subscription::update($remoteSubscriptionId, [
            'items' => [['id' => $sub->items->data[0]->id, 'price' => $newRemotePlanId]],
            'proration_behavior' => 'create_prorations',
        ]);

        return $this->getRemoteSubscription($remoteSubscriptionId);
    }

    public function parseWebhookPayload(string $payload, array $headers): array
    {
        $sigHeader = $headers['stripe-signature'] ?? ($headers['Stripe-Signature'] ?? '');
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
        $invoices = \Stripe\Invoice::all([
            'subscription' => $remoteSubscriptionId,
            'limit'        => 100,
            'expand'       => ['data.payment_intent.payment_method'],
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

        $pmRemoteId = null;
        $pmBrand    = null;
        $pmLast4    = null;
        $pi = $inv->payment_intent ?? null;
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

        return new RemoteInvoiceDTO(
            id:                   (string) $inv->id,
            remoteSubscriptionId: (string) $inv->subscription,
            origin:               $origin,
            status:               $status,
            amount:               ((int) ($inv->amount_paid ?? 0)) / 100.0,
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

    public function currencyRates(): array
    {
        return [
            'CLP' => 1, 'DJF' => 1, 'JPY' => 1, 'KMF' => 1, 'RWF' => 1,
            'VUV' => 1, 'XAF' => 1, 'XOF' => 1, 'BIF' => 1, 'GNF' => 1,
            'KRW' => 1, 'MGA' => 1, 'PYG' => 1, 'VND' => 1, 'XPF' => 1,
        ];
    }

    public function convertPrice($price, $currency)
    {
        $rate = $this->currencyRates()[$currency] ?? 100;
        return (int) round($price * $rate);
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
