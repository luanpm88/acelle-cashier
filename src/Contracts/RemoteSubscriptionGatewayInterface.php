<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\RemotePlanDTO;
use App\Cashier\DTO\RemoteSubscriptionDTO;
use App\Cashier\DTO\RemotePaymentMethodDTO;
use App\Cashier\DTO\RemoteInvoiceDTO;

/**
 * Capability for gateways that manage subscriptions on a remote provider
 * (Stripe, etc.). Read/sync side — write side is via SupportsSubscriptionInterface.
 */
interface RemoteSubscriptionGatewayInterface
{
    /**
     * Fetch all available plans/prices from the remote provider.
     * @return RemotePlanDTO[]
     */
    public function getRemotePlans(): array;

    public function getRemotePlan(string $remotePlanId): RemotePlanDTO;

    public function getRemoteSubscription(string $remoteSubscriptionId): RemoteSubscriptionDTO;

    /**
     * Fetch a page of subscriptions from the remote provider (admin overview).
     * Cursor-based pagination — pass the last item's id as $startingAfter for the next page.
     *
     * @return array{data: RemoteSubscriptionDTO[], has_more: bool, next_cursor: ?string}
     */
    public function getRemoteSubscriptions(?string $startingAfter = null, int $limit = 100): array;

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

    public function updateRemoteSubscriptionPlan(
        string $remoteSubscriptionId,
        string $newRemotePlanId
    ): RemoteSubscriptionDTO;

    public function getRemotePaymentMethod(string $remoteSubscriptionId): ?RemotePaymentMethodDTO;

    public function getWebhookSecret(): ?string;

    /**
     * @return array{event: string, data: array}
     */
    public function parseWebhookPayload(string $payload, array $headers): array;

    /**
     * List billing events (invoices/transactions) for a subscription, oldest-first.
     *
     * Used by the sync layer to detect new auto-billing charges (RECURRING),
     * plan-change prorations, and refunds — vendor objects that don't surface
     * via getRemoteSubscription's scalar `latestInvoiceAmount`.
     *
     * Drivers MUST sort oldest-first so cursor advance is monotonic and a
     * mid-loop materialize failure can resume cleanly on next tick.
     *
     * @param  string       $remoteSubscriptionId  vendor sub id (sub_xxx)
     * @param  string|null  $afterId               vendor invoice/txn id; return events strictly after it
     * @param  int          $limit                 page size cap
     *
     * @return array{data: RemoteInvoiceDTO[], has_more: bool, next_cursor: ?string}
     */
    public function getRemoteInvoices(
        string $remoteSubscriptionId,
        ?string $afterId = null,
        int $limit = 50,
    ): array;
}
