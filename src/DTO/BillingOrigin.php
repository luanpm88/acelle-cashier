<?php

namespace App\Cashier\DTO;

/**
 * Origin of a billing event (vendor invoice / transaction).
 *
 * Vendor drivers map their native field (Stripe `Invoice.billing_reason`,
 * Paddle `Transaction.origin`, etc.) onto these app-level cases so the sync
 * layer can decide what to materialize without knowing the vendor.
 *
 * INITIAL      — first charge from signup hosted-checkout. Local Invoice
 *                 usually already exists (created by checkout flow); sync
 *                 just stamps remote_invoice_id on the existing row.
 * RECURRING    — auto-renewal at next billing cycle. The gap this DTO fixes:
 *                 sync materializes a fresh local Order + Invoice.
 * PLAN_CHANGE  — upgrade / downgrade / proration adjustment.
 * REFUND       — money returned to customer. Materialized as a negative
 *                 Invoice line; doesn't extend subscription period.
 * MANUAL       — admin-issued one-off charge from vendor dashboard with no
 *                 corresponding local intent. Logged only.
 */
enum BillingOrigin: string
{
    case INITIAL     = 'initial';
    case RECURRING   = 'recurring';
    case PLAN_CHANGE = 'plan_change';
    case REFUND      = 'refund';
    case MANUAL      = 'manual';
}
