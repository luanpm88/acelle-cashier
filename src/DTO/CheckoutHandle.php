<?php

namespace App\Cashier\DTO;

/**
 * Where to send the buyer to pay an intent — the return of the SINGLE checkout method
 * {@see \App\Cashier\Contracts\IntentGatewayInterface::getCheckoutUrl()}. A 2-case sum type: the
 * gateway decides internally which it returns; the host branches on the concrete type. Modelling it
 * as distinct subclasses (not one nullable-field DTO) makes an illegal state unrepresentable — a
 * non-pollable checkout can never carry a stray session id.
 *
 *   {@see DirectCheckout}   — host just redirects; no host-tracked session, the gateway owns completion.
 *   {@see PollableCheckout} — host stamps the session id + polls it to completion (webhook-independent).
 *
 * Cashier stays persistence-agnostic: the handle carries data only; the HOST does the stamping.
 */
abstract class CheckoutHandle
{
    public function __construct(
        public readonly string $url,
    ) {}
}
