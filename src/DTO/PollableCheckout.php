<?php

namespace App\Cashier\DTO;

/**
 * A checkout the host can POLL to completion: the gateway built a provider-side session
 * (Stripe `cs_xxx`, Paddle txn, 2C2P invoiceNo, …) whose id the host stamps onto the PaymentIntent
 * (`remote_reference_id` + `expires_at`) and later reads back via `getCheckoutSession()` —
 * webhook-independent. A gateway returning this MUST implement
 * {@see \App\Cashier\Contracts\SupportsRemoteHostedCheckout}.
 *
 *   url        the provider's hosted-checkout URL to redirect the buyer to.
 *   sessionId  the provider session id — the pollable handle.
 *   expiresAt  unix timestamp when the session expires (Stripe ~24h), or null.
 */
final class PollableCheckout extends CheckoutHandle
{
    public function __construct(
        string $url,
        public readonly string $sessionId,
        public readonly ?int $expiresAt = null,
    ) {
        parent::__construct($url);
    }
}
