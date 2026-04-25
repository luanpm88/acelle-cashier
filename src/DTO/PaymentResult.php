<?php

namespace App\Cashier\DTO;

/**
 * Return value of SupportsAutoChargeInterface::autoCharge().
 *
 * Gateway services are PURE — they call the provider, map the response into one of
 * three outcomes, and return. They do NOT mutate DB or invoke handler callbacks.
 * The controller pattern-matches on $status and dispatches the appropriate handler.
 */
class PaymentResult
{
    public const STATUS_SUCCESS         = 'success';
    public const STATUS_FAILED          = 'failed';
    public const STATUS_REQUIRES_ACTION = 'requires_action';

    private function __construct(
        public readonly string $status,
        public readonly ?string $remoteReferenceId = null, // Stripe pi_xxx
        public readonly ?string $clientSecret = null,      // for 3DS challenge
        public readonly ?string $error = null,
        public readonly array $metadata = [],
    ) {}

    public static function success(string $remoteRef, array $meta = []): self
    {
        return new self(
            status: self::STATUS_SUCCESS,
            remoteReferenceId: $remoteRef,
            metadata: $meta,
        );
    }

    public static function failed(string $error, ?string $remoteRef = null): self
    {
        return new self(
            status: self::STATUS_FAILED,
            remoteReferenceId: $remoteRef,
            error: $error,
        );
    }

    public static function requiresAuth(string $clientSecret, string $remoteRef): self
    {
        return new self(
            status: self::STATUS_REQUIRES_ACTION,
            remoteReferenceId: $remoteRef,
            clientSecret: $clientSecret,
        );
    }
}
