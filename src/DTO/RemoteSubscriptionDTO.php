<?php

namespace App\Cashier\DTO;

use Carbon\Carbon;

class RemoteSubscriptionDTO
{
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
        public readonly array $metadata = [],
    ) {}

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
