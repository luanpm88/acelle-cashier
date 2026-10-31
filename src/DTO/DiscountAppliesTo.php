<?php

namespace App\Cashier\DTO;

/**
 * What part of a charge a discount is taken from — the scope of a {@see DiscountSpec}, and the
 * value of the app's `coupons.applies_to` column.
 *
 * ALL — the whole up-front charge. The only scope today: every coupon is ALL.
 *
 * Adding a case is a behaviour change, not a label. Every place that realises a discount
 * `match`es on this enum without a default — the host's CouponService (the amount the discount
 * is computed on) and StripeGateway::couponScope (the Stripe coupon's id and `applies_to`) — so
 * a new case throws UnhandledMatchError there until each one says how it narrows the discount.
 * It never falls through to discounting the whole charge.
 */
enum DiscountAppliesTo: string
{
    case ALL = 'all';
}
