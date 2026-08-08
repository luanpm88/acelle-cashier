<?php

namespace App\Cashier\DTO;

/**
 * Immutable DTO representing a single payment attempt — the unit of work cashier consumes.
 * One Invoice may have many PaymentIntents over time (retry, 3DS reattempt, gateway switch).
 *
 * Hydrated from App\Model\PaymentIntent via toDto() in main app.
 * Cashier never queries DB directly; everything it needs is in this object.
 */
class PaymentIntent
{
    public function __construct(
        public readonly string $uid,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $description,
        public readonly string $paymentGatewayId, // UID of the PaymentGateway record
        public readonly Payer $payer,
        public readonly ?SubscriptionSpec $subscription,  // null = one-off charge
        public readonly array $metadata = [],
        public readonly ?string $remoteReferenceId = null, // gateway-side ID (pi_xxx, sub_xxx)
        public readonly ?DiscountSpec $discount = null,    // null = full price; applied by a SupportsDiscounts gateway
        // FALSE = the buyer explicitly asked for a different card, so the hosted page must not
        // offer the ones already on file. Carried on the DTO rather than as a getCheckoutUrl()
        // parameter because that method is an INTERFACE method with 12 implementers — widening it
        // would break every other gateway plugin. Gateways that do not understand it ignore it.
        public readonly bool $offerSavedCards = true,
    ) {}

    /**
     * Same intent, with the saved-card offer decided.
     *
     * A `with`-er rather than a constructor argument at every call site: the DTO is built from the
     * persisted model (toDto()), and this flag is a property of the REQUEST — which button the buyer
     * pressed — not of the stored row. Nothing about the intent's identity changes, so a clone is
     * the honest shape.
     */
    public function withSavedCardOffer(bool $offer): self
    {
        if ($offer === $this->offerSavedCards) {
            return $this;
        }

        return new self(
            $this->uid, $this->amount, $this->currency, $this->description, $this->paymentGatewayId,
            $this->payer, $this->subscription, $this->metadata, $this->remoteReferenceId,
            $this->discount, $offer,
        );
    }

    public function isSubscription(): bool
    {
        return $this->subscription !== null;
    }

    public function isDiscounted(): bool
    {
        return $this->discount !== null;
    }

    /**
     * The amount to actually charge when a gateway realizes a carried discount by SUBTRACTION
     * rather than a provider coupon object — i.e. the off-session / amount-based path (a hosted
     * Checkout Session instead attaches a provider Coupon and charges the full {@see $amount}).
     *
     * Subtracts the app's already-resolved metadata['coupon_amount'] — the ONE recorded discount
     * (= invoice.discount_amount, already capped + rounded by the host's CouponService) — NOT a
     * value re-computed from the DiscountSpec percent, which would drift on a fixed/capped coupon
     * and mis-bill. Floored at 0 (a 100%-off coupon → $0 → the caller short-circuits to success).
     */
    public function netAmountAfterDiscount(): float
    {
        if ($this->discount === null) {
            return $this->amount;
        }

        $couponAmount = (float) ($this->metadata['coupon_amount'] ?? 0);

        return max(0.0, round($this->amount - $couponAmount, 2));
    }
}
