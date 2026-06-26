<?php

namespace App\Cashier\DTO;

/**
 * Gateway-neutral description of a REMOTE CHECKOUT checkout: charge N one-off items AND
 * start one trialing subscription, collected with a single card entry. The
 * adapter (e.g. StripeSubscriptionGateway::buildRemoteCheckoutUrl) translates
 * this into the provider's hosted-checkout call.
 *
 *   recurringPriceId   the remote recurring price (the subscription).
 *   oneTimePriceIds[]  zero or more remote one-time prices billed up-front on the
 *                      first invoice (e.g. an $80 license fee).
 *   trialDays          free days before the recurring price starts billing
 *                      ($0 first period); null = bill immediately.
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
    ) {}
}
