<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\PaymentResult;

/**
 * Capability: gateway can charge a payment method off-session (no card form).
 * Used for one-off charges and recurring auto-charge.
 */
interface SupportsAutoChargeInterface
{
    /**
     * Attempt the charge. Pure function — must not mutate DB or invoke handler callbacks.
     *
     * @param array $paymentMethodData StripeAutoBillingData::toArray() output
     */
    public function autoCharge(PaymentIntent $intent, array $paymentMethodData): PaymentResult;
}
