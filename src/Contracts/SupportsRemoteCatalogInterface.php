<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\RemotePlanDTO;
use App\Cashier\DTO\RemoteSubscriptionDTO;
use App\Cashier\DTO\RemoteInvoiceDTO;
use App\Cashier\DTO\RemotePlanChangePreviewDTO;
use App\Cashier\DTO\DiscountSpec;
use App\Cashier\DTO\RemotePaymentFailureDTO;

/**
 * Capability: the vendor is backed by a queryable remote CATALOG — plans,
 * subscriptions and invoices are pre-created/enumerable remote objects you can
 * list, fetch, and (for plans) switch a subscription between. Stripe, Paddle and
 * Braintree all work this way.
 *
 * WHY THIS IS A SEPARATE INTERFACE (not part of the base
 * {@see ManageRemoteSubscriptionInterface}):
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

    /**
     * Swap an existing subscription to a new plan/price.
     *
     * @param  bool  $chargeImmediately  false (default): defer the price-difference proration to the
     *   next invoice (provider-native, no charge now). true: charge the difference NOW — the driver
     *   invoices the proration immediately and, if the sub is trialing, ends the trial so the charge
     *   actually lands (a trial bills $0). Used by the "upgrade charges now" flow.
     * @param  DiscountSpec|null  $discount  optional PERCENT-ONLY coupon folded into the proration (a
     *   fixed amount_off can't discount a proration line — drivers MUST reject it). Applied in the SAME
     *   call as the item change so it reduces the immediate proration; pass the same spec to
     *   previewPlanChange() so the quote matches.
     */
    public function updateRemoteSubscriptionPlan(
        string $remoteSubscriptionId,
        string $newRemotePlanId,
        bool $chargeImmediately = false,
        ?int $prorationDate = null,
        ?DiscountSpec $discount = null
    ): RemoteSubscriptionDTO;

    /**
     * Preview what switching a subscription to a new plan WILL cost, WITHOUT creating an invoice or
     * charging anything (read-only upcoming-invoice inquiry). Returns the net proration + the pinned
     * proration date; pass that date to updateRemoteSubscriptionPlan() so the real charge matches the
     * quote to the cent. Pass the same PERCENT-ONLY $discount to preview the coupon-reduced proration.
     */
    public function previewPlanChange(
        string $remoteSubscriptionId,
        string $newRemotePlanId,
        ?int $prorationDate = null,
        ?DiscountSpec $discount = null
    ): RemotePlanChangePreviewDTO;

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

    /**
     * Fetch ONE invoice by its remote id — immediately consistent (a direct retrieve), unlike the
     * list above whose index lags behind creation. Used for read-after-write flows such as
     * materializing a just-created invoice synchronously. Null if it can't map to an invoice DTO.
     */
    public function getRemoteInvoice(string $invoiceId): ?RemoteInvoiceDTO;

    /**
     * Why the last payment attempt on this invoice did not collect — so the app can route recovery
     * (authenticate the same card vs replace it) instead of guessing. Null when the invoice has no
     * failed attempt to explain (never attempted, or already paid).
     *
     * @see \App\Cashier\DTO\RemotePaymentFailureDTO
     */
    public function getRemoteInvoiceFailure(string $invoiceId): ?RemotePaymentFailureDTO;

    /**
     * Abandon a held ("pending") plan change and the invoice raised for it, so a fresh attempt can
     * be quoted from scratch. Required because a held change expires on the vendor's clock while
     * its invoice keeps its ORIGINAL amount: after that window, paying the old invoice takes the
     * buyer's money without applying the change. Callers that cannot pay promptly must discard,
     * not retry. Idempotent — already-void/absent is success.
     */
    public function discardPendingPlanChange(string $remoteSubscriptionId, string $invoiceId): void;

    /**
     * Hosted page where a customer replaces their stored card, returning to $returnUrl when done.
     * The returned handle's url is single-use; $returnUrl receives the vendor's session reference
     * so the caller can resolve WHICH card was stored (see resolveStoredPaymentMethod).
     */
    public function getCardUpdateUrl(string $remoteCustomerId, string $returnUrl, string $cancelUrl): string;

    /**
     * The card stored by a completed {@see getCardUpdateUrl} session — its vendor id, ready to be
     * made the subscription's default. Null when the session did not complete.
     */
    public function resolveStoredPaymentMethod(string $sessionReference): ?string;

    /**
     * Make a stored card the one this subscription charges from now on. Called after a card
     * replacement so the NEXT attempt uses the new card rather than the refused one.
     */
    public function setRemoteSubscriptionPaymentMethod(string $remoteSubscriptionId, string $remotePaymentMethodId): void;
}
