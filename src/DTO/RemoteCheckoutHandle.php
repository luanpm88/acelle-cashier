<?php

namespace App\Cashier\DTO;

/**
 * Result of building a hosted remote checkout: the redirect URL the buyer is sent
 * to, PLUS the provider's session handle (e.g. Stripe `cs_xxx`) and its expiry.
 *
 * The session id is the ONLY handle the app holds BEFORE completion — it is what
 * lets Acelle proactively poll the session status (webhook-independent) instead of
 * waiting for a provider callback. Cashier returns it; the HOST persists it (onto
 * the PaymentIntent row). Cashier stays persistence-agnostic.
 *
 *   url        the provider's hosted-checkout URL to redirect the buyer to.
 *   sessionId  the provider session id (Stripe `cs_xxx`) — the pollable handle.
 *   expiresAt  unix timestamp when the session expires (Stripe ~24h), or null.
 */
class RemoteCheckoutHandle
{
    public function __construct(
        public readonly string $url,
        public readonly string $sessionId,
        public readonly ?int $expiresAt = null,
    ) {}
}
