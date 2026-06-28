<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\RemoteOneTimePriceDTO;

/**
 * Capability: the vendor exposes a queryable ONE-TIME (non-recurring) price
 * catalog — pre-created remote price objects you can list and fetch by id, to
 * attach as one-off add-ons charged up-front in a hosted subscription checkout.
 *
 * WHY THIS IS A SEPARATE INTERFACE (not part of
 * {@see SupportsRemoteHostedCheckout}):
 *
 *   That interface is about the hosted-checkout MECHANISM (build the redirect
 *   URL, poll the session, map the card back). Whether a one-off price CATALOG
 *   exists is an orthogonal DATA-discovery concern. A gateway can run a hosted
 *   checkout whose one-off amounts come from LOCAL data and expose no remote
 *   price catalog at all — 2C2P (RPP) is the canonical example: the amounts are
 *   sent inline per charge, there is NO remote price object to list. Bundling the
 *   catalog into the hosted interface forced such a gateway to FAKE
 *   getRemoteOneTimePrices(); segregating it here lets a catalog-less hosted
 *   gateway honestly NOT declare one. Consumers MUST `instanceof` this before
 *   calling either method.
 *
 * Sibling of {@see SupportsRemoteCatalogInterface} (the RECURRING plan catalog).
 */
interface SupportsRemoteOneTimePriceCatalogInterface
{
    /**
     * List the provider's available ONE-TIME (non-recurring) prices.
     * @return RemoteOneTimePriceDTO[]
     */
    public function getRemoteOneTimePrices(): array;

    /** Fetch a single one-time price by its remote id. */
    public function getRemoteOneTimePrice(string $remotePriceId): RemoteOneTimePriceDTO;
}
