<?php

namespace App\Cashier\DTO;

/**
 * A discount to apply to ONE checkout — present only when the intent carries one
 * (discount = null ⇒ full price). The sibling of {@see SubscriptionSpec}: the FULL,
 * gateway-NEUTRAL description of the discount, assembled ONCE host-side (from the
 * app's Coupon) and stamped onto the intent, so every consumer reads it off
 * `$intent->discount` and never reaches back to app models.
 *
 * It describes only WHAT the discount is — never HOW a provider applies it. Each
 * gateway that {@see \App\Cashier\Contracts\SupportsDiscounts} realises it INSIDE its
 * own getCheckoutUrl, reading only the fields its provider needs:
 *
 *  • CATALOG gateways (Stripe) — turn (type,value,currency) into a provider Coupon
 *    object and attach it to the hosted session.
 *  • AMOUNT-BASED gateways — subtract the computed amount from the charge directly.
 *
 * There is deliberately NO provider id here (e.g. no Stripe `co_…`): a provider id
 * would make this DTO gateway-specific. The provider mapping is the gateway's job.
 *
 * Stripe allows at most one discount per checkout, so this is singular (not a list).
 */
class DiscountSpec
{
    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED   = 'fixed';

    public function __construct(
        /** 'percent' | 'fixed'. */
        public readonly string $type,
        /** Percent (10 = 10%) or fixed amount (20.00) in $currency, in MAJOR units. */
        public readonly float $value,
        /** Required for 'fixed' (pins the amount to a currency); null for 'percent'. */
        public readonly ?string $currency = null,
        /** Scope hint: 'order' (whole checkout) | 'license' (the one-off line). Advisory. */
        public readonly string $appliesTo = 'order',
        /** App-side code, for provider metadata / tracing only — never used to compute. */
        public readonly ?string $code = null,
    ) {}

    /** Serialize for persistence on `payment_intents.metadata` (round-trips via {@see fromArray()}). */
    public function toArray(): array
    {
        return [
            'type'       => $this->type,
            'value'      => $this->value,
            'currency'   => $this->currency,
            'applies_to' => $this->appliesTo,
            'code'       => $this->code,
        ];
    }

    /** Rebuild from the persisted metadata array (the inverse of {@see toArray()}). */
    public static function fromArray(array $d): self
    {
        return new self(
            type:      (string) ($d['type'] ?? self::TYPE_PERCENT),
            value:     (float) ($d['value'] ?? 0),
            currency:  isset($d['currency']) ? (string) $d['currency'] : null,
            appliesTo: (string) ($d['applies_to'] ?? 'order'),
            code:      isset($d['code']) ? (string) $d['code'] : null,
        );
    }
}
