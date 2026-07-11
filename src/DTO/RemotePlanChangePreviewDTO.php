<?php

namespace App\Cashier\DTO;

/**
 * Preview of what a plan change WILL cost — computed via the gateway's upcoming-invoice endpoint
 * WITHOUT creating an invoice or charging anything (read-only).
 *
 * - `amount` (major units): the net proration. Positive = charged now on the change (upgrade);
 *   negative = credited to the customer (downgrade). Equals what updateRemoteSubscriptionPlan()
 *   with chargeImmediately: true will bill, when the SAME `prorationDate` is passed to it.
 * - `prorationDate`: the pinned instant the proration was computed at. Pass it back to
 *   updateRemoteSubscriptionPlan() so the real charge matches this quote to the cent (proration is
 *   per-second — without pinning, the seconds between quote and charge drift the amount).
 */
class RemotePlanChangePreviewDTO
{
    public function __construct(
        public readonly float $amount,
        public readonly string $currency,
        public readonly int $prorationDate,
    ) {}

    public function isCredit(): bool
    {
        return $this->amount < 0;
    }
}
