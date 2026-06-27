<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\RemoteCheckoutSpec;
use App\Cashier\DTO\RemoteCheckoutHandle;
use App\Cashier\DTO\RemoteCheckoutSessionDTO;

/**
 * APPROACH to creating a remote subscription: **delegate to the provider's HOSTED
 * checkout page**. The app builds one redirect URL; the buyer enters the card once
 * on the provider's page; the PROVIDER creates the subscription (+ charges any
 * one-off line items up-front). Completion is reported back via webhook / polling.
 *
 * This is ORTHOGONAL to {@see SupportCreateRemoteSubscription} (the imperative
 * approach where the APP itself calls `createSubscription`). A gateway picks the
 * approach it offers — neither extends the other. One-off add-ons in the same
 * hosted checkout are a DEFAULT part of this approach: the spec's oneTimePriceIds
 * are charged up-front. WHERE those ids come from (a remote price catalog vs local
 * data) is a SEPARATE concern — see {@see SupportsRemoteOneTimePriceCatalogInterface}.
 *
 * OPTIONAL capability — consumers MUST `instanceof` this before using the hosted
 * checkout. This interface is purely the hosted-checkout MECHANISM (build URL +
 * poll session). The recurring plan catalog lives on
 * {@see SupportsRemoteCatalogInterface}; the ONE-TIME price catalog on
 * {@see SupportsRemoteOneTimePriceCatalogInterface}; charging the saved card the buyer
 * entered is {@see SupportsAutoChargeInterface}'s job — each is a separate,
 * independently-`instanceof`'d capability.
 */
interface SupportRemoteSubscriptionViaRemoteCheckoutPage
{
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
