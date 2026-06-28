<?php

namespace App\Cashier\DTO;

/**
 * The subscription a PaymentIntent signs the buyer up for — present only when the
 * intent represents a subscription (subscription = null ⇒ a plain one-off charge).
 *
 * This is the FULL, gateway-neutral description of the (possibly bundled) hosted
 * checkout: the recurring plan, any one-off items billed up-front, the trial, and the
 * raw amounts. It is assembled ONCE host-side from the plan's remote mappings and
 * stamped onto the intent, so every consumer (the gateway's buildRemoteCheckoutUrl,
 * the webhook, the poller) reads everything from `$intent->subscription` and never
 * reaches back to the host Plan model. It supersedes the old separate RemoteCheckoutSpec.
 *
 * Two pricing dialects so one shape serves both kinds of gateway — each adapter reads
 * only the fields its provider needs:
 *
 *  • CATALOG gateways (Stripe) — amounts live in remote Price objects. The adapter sends
 *    only IDs: {@see $remotePlanId} (the recurring price) + {@see $oneTimePriceIds}.
 *    The amount/interval fields are ignored.
 *  • AMOUNT-BASED gateways (2C2P RPP) — no remote price registry; amounts are local and
 *    passed raw: {@see $recurringAmount} + {@see $upfrontAmount} + {@see $intervalDays}.
 *    The price-id fields are empty.
 */
class SubscriptionSpec
{
    public function __construct(
        /** Recurring remote price/plan id (catalog dialect). Empty for amount-based gateways. */
        public readonly string $remotePlanId,
        /** Remote one-time price ids billed up-front on the first invoice (catalog bundling). */
        public readonly array $oneTimePriceIds = [],
        /** Free days before the recurring price starts billing; null = bill immediately. Both dialects. */
        public readonly ?int $trialDays = null,
        // ── amount-based dialect (2C2P RPP); null/ignored by catalog gateways ──
        /** Per-cycle subscription amount. */
        public readonly ?float $recurringAmount = null,
        /** Total charged NOW at the single hosted checkout = Σ one-offs (+ recurring if no trial). */
        public readonly ?float $upfrontAmount = null,
        /** Recurring interval in days. */
        public readonly ?int $intervalDays = null,
    ) {}

    /** Serialize for persistence on `payment_intents.metadata` (round-trips via {@see fromArray()}). */
    public function toArray(): array
    {
        return [
            'remote_plan_id'     => $this->remotePlanId,
            'one_time_price_ids' => $this->oneTimePriceIds,
            'trial_days'         => $this->trialDays,
            'recurring_amount'   => $this->recurringAmount,
            'upfront_amount'     => $this->upfrontAmount,
            'interval_days'      => $this->intervalDays,
        ];
    }

    /** Rebuild from the persisted metadata array (the inverse of {@see toArray()}). */
    public static function fromArray(array $d): self
    {
        return new self(
            remotePlanId:    (string) ($d['remote_plan_id'] ?? ''),
            oneTimePriceIds: $d['one_time_price_ids'] ?? [],
            trialDays:       isset($d['trial_days']) ? (int) $d['trial_days'] : null,
            recurringAmount: isset($d['recurring_amount']) ? (float) $d['recurring_amount'] : null,
            upfrontAmount:   isset($d['upfront_amount']) ? (float) $d['upfront_amount'] : null,
            intervalDays:    isset($d['interval_days']) ? (int) $d['interval_days'] : null,
        );
    }
}
