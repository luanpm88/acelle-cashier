<?php

namespace App\Cashier\DTO;

/**
 * A remote ONE-TIME (non-recurring) price — e.g. a Stripe `one_time` Price used
 * as a one-off item in a remote checkout (a license fee, a setup fee, …). Distinct from
 * {@see RemotePlanDTO} which is recurring (carries interval/trial); a one-time
 * price has none of those, so it gets its own flat DTO rather than null-filling
 * the recurring fields.
 */
class RemoteOneTimePriceDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly float $price,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?string $productId = null,
        public readonly array $metadata = [],
    ) {}

    public function summary(): string
    {
        return "{$this->name}: {$this->price} {$this->currency} (one-time)";
    }
}
