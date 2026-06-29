<?php

namespace App\Cashier\DTO;

/**
 * A checkout the host simply REDIRECTS to — no host-tracked session, no polling. The gateway (or its
 * plugin controller) owns completion via its own return/callback. Covers the offline instructions
 * page AND plugin one-off gateways that redirect the buyer on to a vendor page but expose no
 * host-pollable session.
 *
 *   url  where to send the buyer (a host route, a plugin page, or a vendor page).
 */
final class DirectCheckout extends CheckoutHandle
{
}
