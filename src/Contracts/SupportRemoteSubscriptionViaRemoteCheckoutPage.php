<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\RemoteCheckoutSpec;
use App\Cashier\DTO\RemoteCheckoutHandle;
use App\Cashier\DTO\RemoteCheckoutSessionDTO;
use App\Cashier\DTO\RemoteOneTimePriceDTO;

/**
 * APPROACH to creating a remote subscription: **delegate to the provider's HOSTED
 * checkout page**. The app builds one redirect URL; the buyer enters the card once
 * on the provider's page; the PROVIDER creates the subscription (+ charges any
 * one-off line items up-front). Completion is reported back via webhook / polling.
 *
 * This is ORTHOGONAL to {@see SupportCreateRemoteSubscription} (the imperative
 * approach where the APP itself calls `createSubscription`). A gateway picks the
 * approach it offers — neither extends the other. One-off add-ons in the same
 * hosted checkout are a DEFAULT part of this approach (no separate capability).
 *
 * OPTIONAL capability — consumers MUST `instanceof` this before using the hosted
 * checkout. The recurring catalog lives on {@see SupportsRemoteCatalogInterface};
 * the ONE-TIME price catalog (for the add-ons) is surfaced here.
 */
interface SupportRemoteSubscriptionViaRemoteCheckoutPage
{
    // ── One-time price catalog (add-ons charged in the hosted checkout) ──────

    /**
     * List the provider's available ONE-TIME (non-recurring) prices.
     * @return RemoteOneTimePriceDTO[]
     */
    public function getRemoteOneTimePrices(): array;

    /** Fetch a single one-time price by its remote id. */
    public function getRemoteOneTimePrice(string $remotePriceId): RemoteOneTimePriceDTO;

    // ── Hosted checkout ─────────────────────────────────────────────────────

    /**
     * Build a hosted-checkout session that starts the (trialing) subscription AND
     * charges the spec's one-off items up-front — one redirect, one card entry.
     * The actual charge + subscription creation happen provider-side; completion
     * is reported via webhook / polling. Pure: no DB writes.
     *
     * Returns a {@see RemoteCheckoutHandle} (url + session id + expiry), NOT just a
     * URL: the host persists the session id so it can later poll completion without
     * a webhook (see {@see getCheckoutSession()}).
     */
    public function buildRemoteCheckoutUrl(RemoteCheckoutSpec $spec, string $returnUrl): RemoteCheckoutHandle;

    /**
     * Read back a hosted checkout session by its id — the webhook-INDEPENDENT poll.
     * Lets Acelle proactively decide whether the checkout completed (and pull the
     * created subscription/customer) instead of waiting for a provider callback.
     * Pure: no DB writes. Throws the provider's not-found exception for an unknown id.
     */
    public function getCheckoutSession(string $sessionId): RemoteCheckoutSessionDTO;
}
