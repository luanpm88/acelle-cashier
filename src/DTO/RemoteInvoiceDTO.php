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
        public readonly float $amount,               // major units (49.00, not 4900)
        public readonly string $currency,            // 'USD' (uppercased ISO-4217)
        public readonly ?Carbon $periodStart,
        public readonly ?Carbon $periodEnd,
        public readonly Carbon $billedAt,
        public readonly ?string $failureReason = null,
        public readonly ?string $hostedInvoiceUrl = null,
        // Per-charge payment method snapshot from the vendor side. Vendor is
        // source-of-truth — these fields stay valid even after the customer
        // deletes the card locally. Reverse-lookup local PaymentMethod by
        // matching $paymentMethodRemoteId against autobilling_data's vendor id.
        public readonly ?string $paymentMethodRemoteId = null,   // pm_xxx (Stripe) / paddle card id
        public readonly ?string $paymentMethodBrand = null,      // 'Visa' | 'Mastercard' | ...
        public readonly ?string $paymentMethodLast4 = null,      // '3155'
    ) {}

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'past_due'], true);
    }

    public function isRefund(): bool
    {
        return $this->origin === BillingOrigin::REFUND;
    }
}
