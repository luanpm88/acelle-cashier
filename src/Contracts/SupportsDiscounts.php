<?php

namespace App\Cashier\Contracts;

/**
 * CAPABILITY: the gateway can apply a {@see \App\Cashier\DTO\DiscountSpec} carried on
 * the intent to its checkout — reducing what the buyer is charged.
 *
 * ORTHOGONAL to every other capability (hosted checkout, bundles, subscriptions): a
 * gateway may host checkout yet have no way to apply a discount, so "does hosted
 * checkout" must NOT be read as "does discount". Only a gateway that declares this
 * marker honours `$intent->discount`; a gateway without it IGNORES the field, so the
 * host must check `instanceof SupportsDiscounts` BEFORE promising a buyer a discount
 * (else the preview would show a cut the gateway never applies — an overcharge).
 *
 * Pure marker — no methods: the discount is realised inside getCheckoutUrl, which
 * maps the neutral DiscountSpec to the provider's own mechanism. `instanceof` is the
 * whole contract. Mirrors {@see SupportsBundledItems}.
 */
interface SupportsDiscounts
{
}
