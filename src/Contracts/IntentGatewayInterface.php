<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\PaymentIntent;

/**
 * Base contract for gateways that consume a PaymentIntent (intent-based flow).
 * Implementing gateways:
 * - StripePaymentGateway (one-off, also implements SupportsAutoChargeInterface)
 * - StripeSubscriptionGateway (subscription, also implements SupportsSubscriptionInterface)
 */
interface IntentGatewayInterface
{
    /**
     * Build a checkout URL the user is redirected to.
     * URL embeds intent_uid as the route parameter — no query params needed besides return_url.
     */
    public function getCheckoutUrl(PaymentIntent $intent, string $returnUrl): string;

    /**
     * Short label for a saved payment method belonging to this gateway, e.g. "Visa" / "Offline".
     * Pure formatter over the auto-billing data blob — no I/O.
     */
    public function getMethodTitle(array $billingData): string;

    /**
     * Detail line for a saved payment method, e.g. "**** **** **** 4242".
     * Pure formatter over the auto-billing data blob — no I/O.
     */
    public function getMethodInfo(array $billingData): string;
}
