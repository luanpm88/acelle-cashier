<?php

namespace App\Cashier\DTO;

/**
 * Present on PaymentIntent only when intent represents a subscription signup.
 * Distinguishes one-off payments (subscription = null) from recurring (subscription set).
 */
class SubscriptionSpec
{
    public function __construct(
        public readonly string $remotePlanId,
    ) {}
}
