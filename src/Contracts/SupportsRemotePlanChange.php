<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\DiscountSpec;
use App\Cashier\DTO\RemotePlanChangePreviewDTO;
use App\Cashier\DTO\RemoteSubscriptionDTO;

/**
 * Capability: the vendor can SWITCH a live subscription to another plan AND settle the price
 * difference in that same, merchant-initiated call — no buyer round-trip, no waiting for the next
 * cycle. That whole sentence is the capability; a vendor that satisfies only part of it MUST NOT
 * declare this interface.
 *
 * WHY THIS IS SEPARATE FROM {@see SupportsRemoteCatalogInterface}:
 *
 *   Having a plan catalog and being able to MOVE a subscription between its entries are different
 *   powers, and at least one real vendor has the first without the second. PayPal Subscriptions v1
 *   publishes Billing Plans (`P-…`) that map perfectly for checkout, but its `revise` endpoint is not
 *   a merchant-side switch: a PayPal-funded subscription needs the SUBSCRIBER to log in and
 *   re-consent (and "If re-consent is not done or if it fails, the subscription continues to be
 *   billed on the existing plan"), while a card-funded one does flip — but charges nothing now, since
 *   "Proration and one-time fees aren't automatically supported" and "The new price is effective
 *   starting on the next billing cycle".
 *
 *   That is not a catalog gap, so keeping these methods on the catalog interface forced PayPal to
 *   declare a power it does not have and fake it — which is exactly the money bug this split
 *   prevents: the app quotes a proration, opens a local invoice for it, and the vendor never
 *   collects. The catalog interface's own note invited this ("Split them again only if a real vendor
 *   supports a genuine subset") — PayPal is that vendor.
 *
 * DECIDING WHETHER TO DECLARE IT — say no if ANY of these is true of your vendor:
 *   - the switch needs the buyer to approve/authenticate at the vendor before it takes effect;
 *   - the new price only applies from the next billing cycle;
 *   - there is no proration line the caller can be charged for now.
 *   Refusing is a first-class outcome: the app then tells the customer to cancel and resubscribe,
 *   which is honest and loses nobody money.
 *
 * Consumers MUST `instanceof` this interface before calling any method below
 * (see {@see \App\Library\BillingManager::supportsRemotePlanChange()}).
 *
 * @link https://developer.paypal.com/docs/subscriptions/customize/revise-subscriptions/
 */
interface SupportsRemotePlanChange
{
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

    /**
     * Abandon a held ("pending") plan change and the invoice raised for it, so a fresh attempt can
     * be quoted from scratch. Required because a held change expires on the vendor's clock while
     * its invoice keeps its ORIGINAL amount: after that window, paying the old invoice takes the
     * buyer's money without applying the change. Callers that cannot pay promptly must discard,
     * not retry. Idempotent — already-void/absent is success.
     */
    public function discardPendingPlanChange(string $remoteSubscriptionId, string $invoiceId): void;
}
