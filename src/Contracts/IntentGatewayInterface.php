<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\CheckoutHandle;

/**
 * Base contract for every gateway that consumes a PaymentIntent. ONE checkout method: the gateway
 * decides INTERNALLY how it collects payment (its own on-site page, a provider-hosted page, a
 * vendor redirect…) and returns a {@see CheckoutHandle} sum type. The host never picks the page; it
 * just redirects to `$handle->url` and, for a {@see \App\Cashier\DTO\PollableCheckout}, stamps the
 * session id for the webhook-independent poller.
 */
interface IntentGatewayInterface
{
    /**
     * Build the checkout the buyer is redirected to for this intent. Returns a
     * {@see \App\Cashier\DTO\DirectCheckout} (just a URL — gateway owns completion) or a
     * {@see \App\Cashier\DTO\PollableCheckout} (URL + provider session id the host polls).
     *
     * $cancelUrl is where the provider's Back/cancel button returns the buyer; null ⇒ $returnUrl.
     */
    public function getCheckoutUrl(PaymentIntent $intent, string $returnUrl, ?string $cancelUrl = null): CheckoutHandle;
}
