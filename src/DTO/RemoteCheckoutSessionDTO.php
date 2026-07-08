<?php

namespace App\Cashier\DTO;

use Carbon\Carbon;

/**
 * Normalized read-back of a hosted checkout session, fetched when Acelle proactively
 * checks whether a remote checkout has completed (the webhook-independent poll).
 * The adapter (StripeGateway::getCheckoutSession) maps the provider's
 * raw session into this so the host never touches `\Stripe\*`.
 *
 * Status vocabulary mirrors Stripe Checkout Session:
 *   status         open | complete | expired
 *   paymentStatus  paid | unpaid | no_payment_required
 *
 * COMPLETION criterion is {@see isComplete()} = status===complete AND a subscription
 * was created — NOT paymentStatus===paid: a $0-trial session completes with
 * paymentStatus = no_payment_required (Stripe collects the card, charges $0 now).
 */
class RemoteCheckoutSessionDTO
{
    public const STATUS_OPEN     = 'open';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_EXPIRED  = 'expired';

    public const PAYMENT_PAID                = 'paid';
    public const PAYMENT_UNPAID              = 'unpaid';
    public const PAYMENT_NO_PAYMENT_REQUIRED = 'no_payment_required';

    public function __construct(
        public readonly string $id,                       // cs_xxx
        public readonly string $status,                   // open | complete | expired
        public readonly string $paymentStatus,            // paid | unpaid | no_payment_required
        public readonly ?string $remoteSubscriptionId,    // sub_xxx once created, else null
        public readonly ?string $remoteCustomerId,        // cus_xxx
        public readonly ?Carbon $currentPeriodEnd = null,
        // Buyer billing details the provider collected on its hosted page — carried so
        // the host can backfill the local invoice at completion (poll path). The webhook
        // path builds the same DTO straight from the event's customer_details.
        public readonly ?RemoteBillingDetailsDTO $billingDetails = null,
        // mode:payment sessions (app-handled one-off, no provider subscription): the charge id
        // (pi_xxx) + the card the host must vault so off-session autoCharge renewals work later.
        // Null for mode:subscription sessions (those carry remoteSubscriptionId instead).
        public readonly ?string $paymentIntentId = null,
        public readonly ?PaymentMethodDTO $paymentMethod = null,
        // The ACTUAL settled amounts the provider computed (smallest currency unit — cents / VND),
        // read at completion so the invoice records the EXACT discount + charge, never a locally
        // rounded estimate. Null when the provider doesn't expose them / the session isn't complete.
        public readonly ?int $amountSubtotal = null,   // gross, before discount
        public readonly ?int $amountDiscount = null,   // the discount actually applied
        public readonly ?int $amountTotal    = null,   // net actually charged
    ) {
        if (!in_array($status, [self::STATUS_OPEN, self::STATUS_COMPLETE, self::STATUS_EXPIRED], true)) {
            throw new \InvalidArgumentException("RemoteCheckoutSessionDTO: unknown session status '{$status}'.");
        }
    }

    /**
     * The only safe completion test. Two shapes complete:
     *  - mode:subscription → status===complete AND a subscription was created (handles the $0
     *    no_payment_required trial: Stripe collects the card, charges $0 now).
     *  - mode:payment (app-handled one-off) → status===complete AND a paid charge (paymentIntentId).
     */
    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE && (
            $this->remoteSubscriptionId !== null
            || ($this->paymentIntentId !== null && $this->paymentStatus === self::PAYMENT_PAID)
        );
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }
}
