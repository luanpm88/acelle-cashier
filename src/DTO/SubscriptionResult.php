<?php

namespace App\Cashier\DTO;

/**
 * Return value of SupportCreateRemoteSubscription::createSubscription().
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
        public readonly ?PaymentMethodDTO $card = null,        // the captured card to persist
        public readonly array $metadata = [],
    ) {}

    public static function active(string $subId, string $customerId, int $periodEnd, ?PaymentMethodDTO $card = null): self
    {
        return new self(
            status: self::STATUS_ACTIVE,
            remoteSubscriptionId: $subId,
            remoteCustomerId: $customerId,
            currentPeriodEnd: $periodEnd,
            card: $card,
        );
    }

    public static function requiresAuth(string $subId, string $clientSecret, ?PaymentMethodDTO $card = null): self
    {
        return new self(
            status: self::STATUS_REQUIRES_ACTION,
            remoteSubscriptionId: $subId,
            clientSecret: $clientSecret,
            card: $card,
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
