<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\RemotePaymentMethodDTO;

/**
 * Capability: a remote-checkout gateway can map the card it read back from the
 * provider (RemotePaymentMethodDTO) into the SAME canonical autobilling_data bag
 * that the gateway's own on-site/local path persists — so a card captured on the
 * provider's hosted page is stored identically to one captured on-site, and is
 * reusable as a saved payment method.
 *
 * Each driver owns its own autobilling_data key shape (mirror of
 * {@see SupportsRemoteSubscriptionViaRemoteCheckoutPage} / extractRemotePaymentMethodId);
 * the host stays gateway-agnostic and only calls this through the interface.
 */
interface BuildsAutoBillingDataInterface
{
    /**
     * @return array the canonical autobilling_data bag for this gateway (e.g.
     *               StripeAutoBillingData::toArray() shape). Throws if the DTO
     *               lacks the load-bearing identifiers — callers only invoke this
     *               when a payment method was actually resolved.
     */
    public function buildAutoBillingData(RemotePaymentMethodDTO $pm): array;
}
