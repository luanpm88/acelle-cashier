<?php

namespace App\Cashier\DTO;

class RemotePaymentMethodDTO
{
    public function __construct(
        public readonly ?string $cardType,
        public readonly ?string $last4,
        public readonly ?string $expirationDate,
        public readonly ?string $email,
        public readonly string $type = 'card',
        public readonly array $metadata = [],
    ) {}

    public function getDisplayTitle(): string
    {
        return $this->cardType ?? 'Card';
    }

    public function getDisplayInfo(): string
    {
        return '**** **** **** ' . ($this->last4 ?? '****');
    }

    public function getDisplayExpiry(): string
    {
        return $this->expirationDate ?? '--';
    }
}
