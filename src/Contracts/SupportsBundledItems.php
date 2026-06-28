<?php

namespace App\Cashier\Contracts;

/**
 * CAPABILITY: the gateway's hosted checkout can charge ONE-OFF items alongside the
 * (single) recurring subscription in ONE interaction — a "bundle".
 *
 * This is ORTHOGONAL to {@see SupportsRemoteHostedCheckout}, which is
 * only the hosted-checkout MECHANISM (build a URL + poll a session). A gateway can run a
 * hosted checkout for a PURE subscription yet NOT be able to add one-off line items, so
 * "does hosted checkout" must NOT be read as "does bundle". Only a gateway that declares
 * this marker honours the spec's `oneTimePriceIds` / `upfrontAmount`; for a non-bundle
 * gateway the host never adds one-off items to the plan, so its checkout spec carries none.
 *
 * Also orthogonal to WHERE the one-off prices come from: a remote price catalog
 * ({@see SupportsRemoteOneTimePriceCatalogInterface}, e.g. Stripe) vs local amounts typed
 * by the admin (e.g. 2C2P RPP). Both are "bundled". So the plan UI uses TWO independent
 * gates: this marker decides WHETHER one-off products may be added at all; the catalog
 * interface decides only HOW they are entered (remote picker vs manual name + amount).
 *
 * Pure marker — no methods: the bundle is realised inside buildRemoteCheckoutUrl, which
 * reads the spec's one-off fields. `instanceof` is the whole contract.
 */
interface SupportsBundledItems
{
}
