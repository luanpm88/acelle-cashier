<?php

namespace App\Cashier\DTO;

use Carbon\Carbon;

class RemoteSubscriptionDTO
{
    /**
     * Canonical subscription-status vocabulary (mirrors Stripe's set — the
     * model this DTO is built on). EVERY gateway driver MUST map its native
     * vendor status into ONE of these BEFORE constructing the DTO; the driver
     * owns that interpretation, the DTO only carries the canonical value.
     *
     * Enforced in the constructor: an unknown status fails LOUD instead of
     * silently making every is*() predicate return false (which would render
     * a real subscription as "no state" and rot undetected). The predicate
     * methods below derive purely from this validated vocabulary.
     */
    public const VALID_STATUSES = [
        'active',
        'trialing',
        'past_due',
        'unpaid',
        'incomplete',
        'incomplete_expired',
        'canceled',
        'paused',
    ];

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $remotePlanId,
        public readonly ?string $remoteCustomerId,
        public readonly ?Carbon $currentPeriodEnd,
        public readonly ?Carbon $currentPeriodStart,
        public readonly ?Carbon $canceledAt,
        public readonly ?float $latestInvoiceAmount,
        public readonly ?string $latestInvoiceStatus,
        public readonly ?string $latestInvoiceId = null,   // in_xxx — sub's latest invoice, for by-id materialize
        public readonly array $metadata = [],
    ) {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "RemoteSubscriptionDTO: unknown status '{$status}'. The gateway driver must map its "
                . 'vendor status into one of: ' . implode(', ', self::VALID_STATUSES) . '.'
            );
        }
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'trialing']);
    }

    public function isCanceled(): bool
    {
        return $this->status === 'canceled';
    }

    public function isPastDue(): bool
    {
        return in_array($this->status, ['past_due', 'unpaid']);
    }

    public function isIncomplete(): bool
    {
        return $this->status === 'incomplete';
    }

    public function isIncompleteExpired(): bool
    {
        return $this->status === 'incomplete_expired';
    }

    public function isTrialing(): bool
    {
        return $this->status === 'trialing';
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['canceled', 'incomplete_expired']);
    }

    public function hasIssue(): bool
    {
        return in_array($this->status, ['past_due', 'unpaid', 'incomplete', 'paused']);
    }
}
