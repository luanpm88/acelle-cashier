<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\RemoteSubscriptionDTO;
use App\Cashier\DTO\RemotePaymentMethodDTO;

/**
 * Capability for gateways that manage subscriptions on a remote provider
 * (Stripe, Paddle, Braintree, 2C2P …). Read/sync side — write side is via
 * SupportCreateRemoteSubscription.
 *
 * This is the BY-ID / lifecycle / webhook core that EVERY remote-subscription
 * gateway supports. The catalog & enumeration methods (plan catalog, list
 * subscriptions, invoice history) are split into one capability interface a
 * gateway may additionally implement:
 *   - {@see SupportsRemoteCatalogInterface} — plans + list-subs + invoice history
 *
 * An inquiry-only gateway (2C2P RPP — recurring terms embedded per charge, no
 * catalog, no list API) implements ONLY this base. Consumers MUST `instanceof`
 * the capability interface before calling a catalog/enumeration method.
 */
interface ManageRemoteSubscriptionInterface
{
    public function getRemoteSubscription(string $remoteSubscriptionId): RemoteSubscriptionDTO;

    public function cancelRemoteSubscription(string $remoteSubscriptionId): void;

    /**
     * Extract the vendor's payment-method id from a local PaymentMethod's
     * `autobilling_data` JSON. Different gateways use different key names
     * (Stripe: `stripe_payment_method`, Paddle: `paddle_payment_method_id`,
     * etc.) — having the driver own the key avoids hardcoded fallback chains
     * in core code.
     *
     * Returns null if the gateway doesn't track per-customer payment methods
     * locally (e.g. Paddle hosted-checkout where vendor owns the card vault).
     * Used by the admin invoice-mapping view to reverse-lookup local PM rows
     * from vendor invoice's payment_method_id.
     */
    public function extractRemotePaymentMethodId(array $autobillingData): ?string;

    /**
     * Undo a soft-cancellation: tell the vendor to resume billing past the
     * current period end. Symmetric counterpart of cancelRemoteSubscription().
     *
     * Behaviour by vendor:
     *   - Stripe:   set `cancel_at_period_end` back to false on the Subscription
     *   - Paddle:   PATCH the subscription to clear `scheduled_change`
     *
     * Idempotent: calling on a sub that wasn't soft-cancelled is a no-op (or
     * the vendor returns a 200 with no state change).
     */
    public function resumeRemoteSubscription(string $remoteSubscriptionId): void;

    public function getRemotePaymentMethod(string $remoteSubscriptionId): ?RemotePaymentMethodDTO;

    public function getWebhookSecret(): ?string;

    /**
     * @return array{event: string, data: array}
     */
    public function parseWebhookPayload(string $payload, array $headers): array;
}
