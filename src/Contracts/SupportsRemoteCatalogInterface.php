<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\RemotePlanDTO;
use App\Cashier\DTO\RemoteSubscriptionDTO;
use App\Cashier\DTO\RemoteInvoiceDTO;

/**
 * Capability: the vendor is backed by a queryable remote CATALOG — plans,
 * subscriptions and invoices are pre-created/enumerable remote objects you can
 * list, fetch, and (for plans) switch a subscription between. Stripe, Paddle and
 * Braintree all work this way.
 *
 * WHY THIS IS A SEPARATE INTERFACE (not part of the base
 * {@see RemoteSubscriptionGatewayInterface}):
 *
 *   Not every gateway has a catalog. Some create each subscription
 *   DYNAMICALLY — the recurring terms (amount, interval, count) are embedded
 *   directly in the charge/checkout request, there is NO pre-created remote plan
 *   object, and the vendor exposes no "list plans / list subscriptions / list
 *   invoices" surface — only by-id inquiry. 2C2P (RPP — Recurring Payment Plan)
 *   is the canonical example: its recurring schedule lives on the LOCAL plan and
 *   is sent inline with every request.
 *
 *   Before this split such a gateway had to FAKE the whole catalog to satisfy
 *   the fat base interface — empty getRemotePlans()/getRemoteSubscriptions()/
 *   getRemoteInvoices(), a fabricated getRemotePlan() DTO, a throwing
 *   updateRemoteSubscriptionPlan(). Segregating the catalog here lets a dynamic
 *   gateway implement ONLY the by-id base interface and honestly NOT declare a
 *   catalog. Consumers MUST `instanceof` this interface before calling any
 *   method below.
 *
 * Note: these capabilities cluster (a gateway either has the full pull-based
 * catalog or none of it), which is why they live on one interface rather than
 * three. Split them again only if a real vendor supports a genuine subset.
 */
interface SupportsRemoteCatalogInterface
{
    // ── Plan catalog ──────────────────────────────────────────────────────

    /**
     * Fetch all available plans/prices from the remote provider.
     * @return RemotePlanDTO[]
     */
    public function getRemotePlans(): array;

    public function getRemotePlan(string $remotePlanId): RemotePlanDTO;

    public function updateRemoteSubscriptionPlan(
        string $remoteSubscriptionId,
        string $newRemotePlanId
    ): RemoteSubscriptionDTO;

    // ── Subscription listing ──────────────────────────────────────────────

    /**
     * Fetch a page of subscriptions from the remote provider (admin overview).
     * Cursor-based — pass the last item's id as $startingAfter for the next page.
     *
     * @return array{data: RemoteSubscriptionDTO[], has_more: bool, next_cursor: ?string}
     */
    public function getRemoteSubscriptions(?string $startingAfter = null, int $limit = 100): array;

    // ── Invoice history ───────────────────────────────────────────────────

    /**
     * List billing events (invoices/transactions) for a subscription, oldest-first.
     *
     * Drivers MUST sort oldest-first. The CALLER is responsible for dedup (it
     * keys on the remote invoice id + a unique DB index), so a driver MAY return
     * a superset — e.g. the full history — and $afterId is only a best-effort
     * "newer than" hint, NOT a guarantee. Returning the full list with
     * has_more=false is valid and preferred where the vendor SDK can auto-drain,
     * because a strict "newer than X" cursor can silently skip older charges on
     * the first sync of a sub that already has vendor-side history.
     *
     * @param  string       $remoteSubscriptionId  vendor sub id
     * @param  string|null  $afterId               best-effort "newer than" hint (may be ignored)
     * @param  int          $limit                 page-size hint (may be ignored when auto-draining)
     * @return array{data: RemoteInvoiceDTO[], has_more: bool, next_cursor: ?string}
     */
    public function getRemoteInvoices(
        string $remoteSubscriptionId,
        ?string $afterId = null,
        int $limit = 50,
    ): array;
}
