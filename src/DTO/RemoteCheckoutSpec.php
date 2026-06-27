<?php

namespace App\Cashier\DTO;

/**
 * Gateway-neutral description of a REMOTE CHECKOUT checkout: charge N one-off items AND
 * start one trialing subscription, collected with a single card entry. The
 * adapter (e.g. StripeSubscriptionGateway::buildRemoteCheckoutUrl) translates
 * this into the provider's hosted-checkout call.
 *
 * The spec speaks TWO pricing dialects so one host orchestration serves both kinds
 * of gateway — each adapter reads only the fields its provider needs:
 *
 *  • CATALOG gateways (Stripe) — amounts live in a remote Price object. The adapter
 *    sends only IDs: {@see $recurringPriceId} + {@see $oneTimePriceIds}. The
 *    amount/currency/interval fields below are ignored.
 *  • AMOUNT-BASED gateways (2C2P RPP) — no remote price registry; amounts are defined
 *    locally and passed in raw: {@see $recurringAmount} + {@see $upfrontAmount} +
 *    {@see $currency} + {@see $intervalDays}. The price-id fields are null/empty.
 *
 *   recurringPriceId   the remote recurring price id (the subscription). Empty for
 *                      amount-based gateways.
 *   oneTimePriceIds[]  zero or more remote one-time price ids billed up-front on the
 *                      first invoice (e.g. an $80 license fee). Empty for amount-based.
 *   trialDays          free days before the recurring price starts billing
 *                      ($0 first period); null = bill immediately. Shared — both
 *                      dialects use it (catalog → trial_period_days; amount-based →
 *                      chargeNextDate = now + trialDays).
 *
 *   recurringAmount    per-cycle amount of the subscription (amount-based dialect).
 *   upfrontAmount      total charged NOW = Σ one-offs (+ recurring if no trial) — what
 *                      the buyer pays at the single hosted checkout (amount-based dialect).
 *   currency           ISO currency code for the raw amounts (amount-based dialect).
 *   intervalDays       recurring interval in days (amount-based dialect).
 *   clientReferenceId  host-side reference the gateway can echo back / key callbacks on
 *                      (the local PaymentIntent uid). Stripe → client_reference_id;
 *                      2C2P → its backend-webhook + invoiceNo routing.
 *
 * Customer: pass a provider customer id (preferred, lets the sub attach to an
 * existing record) OR an email for the provider to create/lookup one.
 */
class RemoteCheckoutSpec
{
    public function __construct(
        public readonly string $recurringPriceId,
        public readonly array $oneTimePriceIds = [],
        public readonly ?int $trialDays = null,
        public readonly ?string $customerId = null,
        public readonly ?string $customerEmail = null,
        public readonly array $metadata = [],
        public readonly ?string $cancelUrl = null,
        // ── amount-based dialect (2C2P RPP); null/ignored by catalog gateways ──
        public readonly ?float $recurringAmount = null,
        public readonly ?float $upfrontAmount = null,
        public readonly ?string $currency = null,
        public readonly ?int $intervalDays = null,
        public readonly ?string $clientReferenceId = null,
    ) {}
}
