<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\RemoteCheckoutSessionDTO;

/**
 * Marks a gateway whose checkout is POLLABLE: its {@see IntentGatewayInterface::getCheckoutUrl()}
 * returns a {@see \App\Cashier\DTO\PollableCheckout} carrying a provider-side session id, and this
 * interface supplies the webhook-INDEPENDENT read-back of that session.
 *
 * The host stamps the session id onto `payment_intents.remote_reference_id` and later calls
 * {@see getCheckoutSession()} to proactively decide whether the checkout completed (and pull the
 * created subscription / customer / charge) instead of waiting for a provider callback.
 *
 * CONTRACT: a gateway returns a {@see \App\Cashier\DTO\PollableCheckout} ⟺ it implements this
 * interface. A gateway whose checkout is a plain redirect (offline, plugin one-off) returns a
 * {@see \App\Cashier\DTO\DirectCheckout} and does NOT implement this — there is no session to poll.
 *
 * OPTIONAL capability — consumers MUST `instanceof` this before polling. Charging a saved card off
 * session is {@see SupportsAutoChargeInterface}'s job; the recurring catalog lives on
 * {@see SupportsRemoteCatalogInterface} — each is a separate, independently `instanceof`'d capability.
 */
interface SupportsRemoteHostedCheckout
{
    /**
     * Read back a checkout session by its provider-side id — the webhook-INDEPENDENT poll. Pure:
     * no DB writes. Throws the provider's not-found exception for an unknown id.
     *
     * Stays string-keyed (not intent-keyed): both callers — the host poller (passes the intent's
     * stored `remote_reference_id`) and the plugins' return controllers (pass the vendor token from
     * the return URL) — already hold the session id. It is a lookup primitive.
     */
    public function getCheckoutSession(string $sessionId): RemoteCheckoutSessionDTO;
}
