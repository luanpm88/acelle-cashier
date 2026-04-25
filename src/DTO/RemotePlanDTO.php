<?php

namespace App\Cashier\DTO;

class RemotePlanDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly float $price,
        public readonly string $currency,
        public readonly int $intervalCount,
        public readonly string $intervalUnit,
        public readonly string $status,
        public readonly ?int $trialDays = null,
        public readonly array $metadata = [],
    ) {}

    public function summary(): string
    {
        $s = "{$this->name}: {$this->price} {$this->currency} every {$this->intervalCount} {$this->intervalUnit}";
        if ($this->trialDays) {
            $s .= " ({$this->trialDays}-day trial)";
        }
        return $s;
    }
}
