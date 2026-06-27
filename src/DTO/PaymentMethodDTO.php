<?php

namespace App\Cashier\DTO;

/**
 * The canonical representation of a saved payment method (card). It is THE shape
 * persisted as `payment_methods.details` (via {@see \App\Model\PaymentMethod::toDto()})
 * — there is no per-gateway bag anymore. {@see toArray()}/{@see fromArray()} are the
 * single (de)serialisation codec; reading a field (e.g. {@see $remotePaymentMethodId})
 * replaces the old driver-specific extractors.
 *
 * Gateway-specific extras a driver wants to keep ride along in {@see $metadata};
 * toArray() flattens them next to the canonical keys (and fromArray() folds any
 * unknown keys back into metadata), so a driver's display reader can still read
 * its own key without the host knowing about it.
 */
class PaymentMethodDTO
{
    /** Canonical top-level keys; everything else in a bag is folded into metadata. */
    private const CANONICAL_KEYS = [
        'remote_payment_method_id', 'remote_customer_id', 'card_type', 'last_4',
        'exp_month', 'exp_year', 'email', 'type', 'auto_charge',
    ];

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
        // Whether THIS card is usable for off-session auto-charge. The GATEWAY decides
        // (it knows if the reusable ids are present + the vendor supports it) and sets
        // it when it builds the DTO; the host stores it into PaymentMethod.can_auto_charge.
        public readonly bool $autoCharge = false,
    ) {}

    /** Serialise to the persisted autobilling_data bag (canonical keys + flattened metadata). */
    public function toArray(): array
    {
        return array_merge($this->metadata, [
            'remote_payment_method_id' => $this->remotePaymentMethodId,
            'remote_customer_id'       => $this->remoteCustomerId,
            'card_type'                => $this->cardType,
            'last_4'                   => $this->last4,
            'exp_month'                => $this->expMonth,
            'exp_year'                 => $this->expYear,
            'email'                    => $this->email,
            'type'                     => $this->type,
            'auto_charge'              => $this->autoCharge,
        ]);
    }

    /** Deserialise a stored bag (array, or its JSON string) back into a DTO. */
    public static function fromArray(array|string $data): self
    {
        $d = is_string($data) ? (json_decode($data, true) ?: []) : $data;

        $expMonth = isset($d['exp_month']) ? (int) $d['exp_month'] : null;
        $expYear  = isset($d['exp_year'])  ? (int) $d['exp_year']  : null;

        return new self(
            cardType:              $d['card_type'] ?? null,
            last4:                 $d['last_4'] ?? null,
            expirationDate:        ($expMonth && $expYear) ? sprintf('%d/%d', $expMonth, $expYear) : ($d['expiration_date'] ?? null),
            email:                 $d['email'] ?? null,
            type:                  $d['type'] ?? 'card',
            metadata:              array_diff_key($d, array_flip(self::CANONICAL_KEYS)),
            remotePaymentMethodId: $d['remote_payment_method_id'] ?? null,
            remoteCustomerId:      $d['remote_customer_id'] ?? null,
            expMonth:              $expMonth,
            expYear:               $expYear,
            autoCharge:            (bool) ($d['auto_charge'] ?? false),
        );
    }

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
