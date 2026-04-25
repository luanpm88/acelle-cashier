<?php

namespace App\Cashier\DTO;

/**
 * Return value of SupportsSubscriptionInterface::createSubscription().
 *
 * Same pattern as PaymentResult — pure, no side-effects. Controller dispatches.
 */
class SubscriptionResult
{
    public const STATUS_ACTIVE          = 'active';
    public const STATUS_REQUIRES_ACTION = 'requires_action';
    public const STATUS_FAILED          = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly ?string $remoteSubscriptionId = null,
        public readonly ?string $remoteCustomerId = null,
        public readonly ?int $currentPeriodEnd = null,        // unix timestamp
        public readonly ?string $clientSecret = null,
        public readonly ?string $error = null,
        public readonly array $paymentMethodData = [],         // card_type, last_4, ...
        public readonly array $metadata = [],
    ) {}

    public static function active(string $subId, string $customerId, int $periodEnd, array $pmData = []): self
    {
        return new self(
            status: self::STATUS_ACTIVE,
            remoteSubscriptionId: $subId,
            remoteCustomerId: $customerId,
            currentPeriodEnd: $periodEnd,
            paymentMethodData: $pmData,
        );
    }

    public static function requiresAuth(string $subId, string $clientSecret, array $pmData = []): self
    {
        return new self(
            status: self::STATUS_REQUIRES_ACTION,
            remoteSubscriptionId: $subId,
            clientSecret: $clientSecret,
            paymentMethodData: $pmData,
        );
    }

    public static function failed(string $error): self
    {
        return new self(
            status: self::STATUS_FAILED,
            error: $error,
        );
    }
}
