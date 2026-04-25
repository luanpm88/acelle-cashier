<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\SubscriptionResult;

/**
 * Capability: gateway can create a recurring subscription on the remote provider.
 * Intent must carry SubscriptionSpec (remotePlanId).
 */
interface SupportsSubscriptionInterface
{
    /**
     * Create the remote subscription. Pure function — no side-effects.
     */
    public function createSubscription(PaymentIntent $intent, array $paymentMethodData): SubscriptionResult;
}
