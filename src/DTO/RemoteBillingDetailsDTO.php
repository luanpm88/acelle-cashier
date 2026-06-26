<?php

namespace App\Cashier\DTO;

/**
 * Normalized billing details read back from a hosted checkout's `customer_details`
 * (name + email + phone + structured address) — the buyer's info the provider
 * collected on its own page. The adapter maps the provider's raw shape into this so
 * the host never touches `\Stripe\*` / raw arrays; the host writes it onto the local
 * invoice at completion.
 *
 * Note: the provider gives a single `name` string (no first/last split). Splitting is
 * the host's concern; this DTO carries it verbatim.
 */
class RemoteBillingDetailsDTO
{
    public function __construct(
        public readonly ?string $name,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?string $line1,
        public readonly ?string $line2,
        public readonly ?string $city,
        public readonly ?string $state,
        public readonly ?string $postalCode,
        public readonly ?string $country,      // ISO-3166-1 alpha-2 (e.g. "VN")
    ) {}

    /**
     * Build from a provider `customer_details` payload. Accepts either the Stripe SDK
     * object (ArrayAccess) from the poll path or a plain decoded array from the webhook
     * — both support `$cd['key']` access.
     */
    public static function fromStripeCustomerDetails($cd): self
    {
        $addr = $cd['address'] ?? [];

        return new self(
            name:       $cd['name'] ?? null,
            email:      $cd['email'] ?? null,
            phone:      $cd['phone'] ?? null,
            line1:      $addr['line1'] ?? null,
            line2:      $addr['line2'] ?? null,
            city:       $addr['city'] ?? null,
            state:      $addr['state'] ?? null,
            postalCode: $addr['postal_code'] ?? null,
            country:    $addr['country'] ?? null,
        );
    }

    /**
     * The whole address as one string (every non-empty part, incl. country) — the
     * provider gives structured fields but the local invoice has a single address
     * column and no country FK to resolve, so we don't split/parse: just join it all.
     */
    public function composedAddress(): string
    {
        return implode(', ', array_filter([
            $this->line1, $this->line2, $this->city, $this->state, $this->postalCode, $this->country,
        ]));
    }
}
