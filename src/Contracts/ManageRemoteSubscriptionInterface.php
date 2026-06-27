<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\RemoteSubscriptionDTO;
use App\Cashier\DTO\PaymentMethodDTO;

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

    public function getRemotePaymentMethod(string $remoteSubscriptionId): ?PaymentMethodDTO;

    public function getWebhookSecret(): ?string;

    /**
     * @return array{event: string, data: array}
     */
    public function parseWebhookPayload(string $payload, array $headers): array;
}
