<?php

namespace App\Cashier\DTO;

/**
 * Immutable DTO representing a single payment attempt — the unit of work cashier consumes.
 * One Invoice may have many PaymentIntents over time (retry, 3DS reattempt, gateway switch).
 *
 * Hydrated from App\Model\PaymentIntent via toDto() in main app.
 * Cashier never queries DB directly; everything it needs is in this object.
 */
class PaymentIntent
{
    public function __construct(
        public readonly string $uid,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $description,
        public readonly string $paymentGatewayId, // UID of the PaymentGateway record
        public readonly Payer $payer,
        public readonly ?SubscriptionSpec $subscription,  // null = one-off charge
        public readonly array $metadata = [],
    ) {}

    public function isSubscription(): bool
    {
        return $this->subscription !== null;
    }
}
