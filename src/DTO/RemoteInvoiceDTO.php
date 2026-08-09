<?php

namespace App\Cashier\DTO;

use Carbon\Carbon;

/**
 * One billing event on the remote provider.
 *
 * Same shape across vendors:
 *   - Stripe Subscription:   1 Invoice  (in_xxx) per cycle
 *   - Paddle Billing:        1 Transaction (txn_xxx) per cycle
 *
 * Both are coerced into this DTO by the vendor driver. The sync layer only
 * sees this — it never touches Stripe.Invoice or Paddle.Transaction directly.
 *
 * `id` is the dedupe key. Combined with `payment_gateway_id` on the local
 * Invoice row, gives a unique constraint that protects re-runs and concurrent
 * syncs from creating duplicates.
 */
class RemoteInvoiceDTO
{
    public function __construct(
        public readonly string $id,                  // in_xxx (Stripe) / txn_xxx (Paddle) — dedupe key
        public readonly string $remoteSubscriptionId,
        public readonly BillingOrigin $origin,
        public readonly string $status,              // 'paid' | 'failed' | 'past_due' | 'refunded' | 'open'
        public readonly float $amount,               // NET charged (amount_paid), major units (49.00, not 4900)
        // What the provider is STILL trying to collect on this invoice (amount_remaining), major
        // units. 0 once it is paid. NOT derivable from $amount: an unpaid invoice has amount_paid
        // = 0, so anything built from $amount alone represents a renewal the customer owes as
        // costing nothing — a $0 local mirror, a "pay $0" button.
        public readonly float $amountDue,
        public readonly string $currency,            // 'USD' (uppercased ISO-4217)
        public readonly ?Carbon $periodStart,
        public readonly ?Carbon $periodEnd,
        public readonly Carbon $billedAt,
        // The provider ATTEMPTED to collect this invoice and did not get the money.
        //
        // Deliberately a fact the DRIVER states, not something inferred from $status here. Vendors
        // do not agree on where that fact lives: Stripe never writes a "failed" invoice status at
        // all — a declined invoice sits at `open` and the only evidence is attempt_count — so a
        // status-based guess reads false for every failure Stripe has. Only the driver knows its
        // vendor's bookkeeping; this field is where it says so.
        //
        // Distinct from "not paid": an invoice finalized seconds ago is unpaid with nothing failed.
        public readonly bool $collectionFailed,
        // Vendor-side discount applied to THIS invoice (e.g. a Stripe coupon), major units. 0 = none.
        // `amount` above is the NET charged; `amount + discountAmount` = the pre-discount (gross) subtotal.
        public readonly float $discountAmount = 0.0,
        public readonly ?string $failureReason = null,
        public readonly ?string $hostedInvoiceUrl = null,
        // Per-charge payment method snapshot from the vendor side. Vendor is
        // source-of-truth — these fields stay valid even after the customer
        // deletes the card locally. Reverse-lookup local PaymentMethod by
        // matching $paymentMethodRemoteId against autobilling_data's vendor id.
        public readonly ?string $paymentMethodRemoteId = null,   // pm_xxx (Stripe) / paddle card id
        public readonly ?string $paymentMethodBrand = null,      // 'Visa' | 'Mastercard' | ...
        public readonly ?string $paymentMethodLast4 = null,      // '3155'
        // Buyer billing snapshot the vendor stamped onto THIS invoice (Stripe:
        // customer_name/email/phone/customer_address). Vendor is source-of-truth for a
        // renewal it billed, so the local renewal invoice is filled from here rather than
        // the customer's default billing address. Null for vendors that don't surface it.
        public readonly ?RemoteBillingDetailsDTO $billingDetails = null,
    ) {}

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * The provider tried to collect and failed — see $collectionFailed.
     *
     * This used to test `status in ['failed','past_due']`, which no Stripe invoice can ever carry
     * (its statuses are draft/open/paid/void/uncollectible), so it answered NO to every declined
     * renewal. Nothing downstream noticed, because the only caller uses it to pick a log label:
     * a failed collection was recorded as an ordinary "renew order".
     */
    public function isFailed(): bool
    {
        return $this->collectionFailed;
    }

    public function isRefund(): bool
    {
        return $this->origin === BillingOrigin::REFUND;
    }
}
