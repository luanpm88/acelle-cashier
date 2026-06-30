<?php

namespace App\Cashier\Exceptions;

/**
 * Thrown by a gateway's getCheckoutUrl() when it cannot honor the requested checkout MODE
 * carried by the PaymentIntent — i.e. the caller asked for a one-off (`$intent->subscription`
 * is null) but this gateway only does provider-managed subscriptions (Paddle, 2C2P RPP), or
 * vice-versa.
 *
 * This is the gateway being the authority on its own capability (the design's two-axes rule:
 * capability = behavior, not a caller-side flag). The app NEVER pre-checks one-off ability —
 * it assumes, attempts, and catches THIS specific class to fall back to another payment method.
 *
 * Deliberately distinct from a bare \LogicException so callers can catch ONLY the
 * "wrong mode for this gateway" case and let genuine misconfiguration (e.g. a subscription
 * gateway missing its local amounts) keep propagating loud.
 */
class UnsupportedCheckoutModeException extends \LogicException
{
}
