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
        // The reusable vendor identifiers — load-bearing for off-session charge +
        // admin PM linking. A hosted-checkout card IS a real provider PaymentMethod
        // attached to a real Customer; carry both so the saved card can be charged
        // later (e.g. Stripe pm_… + cus_…), not just displayed.
        public readonly ?string $remotePaymentMethodId = null,
        public readonly ?string $remoteCustomerId = null,
        // Split expiry (ints) so consumers don't re-parse the "9/2027" string.
        public readonly ?int $expMonth = null,
        public readonly ?int $expYear = null,
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
