<?php

namespace App\Cashier\DTO;

/**
 * Why a gateway could not collect a subscription charge — the discriminator an app needs to pick a
 * recovery route, kept gateway-neutral.
 *
 * The distinction that matters is not "which error code" but WHO can fix it and HOW:
 *
 *   - {@see $requiresAction} — the card is fine, the issuer wants the cardholder to authenticate
 *     (3-D Secure). Recovery = the SAME card, one interactive step. Nothing to change.
 *   - {@see $needsNewPaymentMethod} — the attempt was refused, and the provider put the payment back in
 *     `requires_payment_method` — documented as "so that the payment can be retried". Recovery needs a
 *     payment method supplied AGAIN: usually a different card, though a reason like insufficient_funds
 *     could clear on its own. What the app must not do is silently re-charge the same stored card.
 *
 * Anything else (gateway outage, currency not supported, risk block) is neither: it leaves both
 * flags false so the caller falls back to its generic "we couldn't take the payment" path instead
 * of promising a fix that would not work.
 */
final class RemotePaymentFailureDTO
{
    public function __construct(
        /** Provider status of the payment attempt, verbatim (e.g. requires_action, requires_payment_method). */
        public readonly ?string $status = null,
        /** True when the cardholder must authenticate (3-D Secure) — same card, interactive step. */
        public readonly bool $requiresAction = false,
        /** True when the attempt was refused and a payment method must be supplied again to retry. */
        public readonly bool $needsNewPaymentMethod = false,
        /** Provider decline code, verbatim (e.g. expired_card, insufficient_funds). Audit + support. */
        public readonly ?string $declineCode = null,
        /** Human-readable message from the provider — safe to show the buyer; they can act on it. */
        public readonly ?string $message = null,
    ) {
    }

    /** Neither actionable class — caller should use its generic failure copy. */
    public function isUnclassified(): bool
    {
        return ! $this->requiresAction && ! $this->needsNewPaymentMethod;
    }
}
