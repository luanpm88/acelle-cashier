<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\PaymentMethodDTO;
use App\Cashier\DTO\SubscriptionResult;

/**
 * APPROACH to creating a remote subscription: the **APP imperatively creates it**
 * — collect a payment method on-site, then call `createSubscription()` which hits
 * the provider API (e.g. \Stripe\Subscription::create) to create the recurring
 * subscription. Orthogonal to {@see SupportsRemoteHostedCheckout}
 * (hosted-page approach); neither extends the other.
 *
 * REFERENCE ONLY at present — no gateway implements this approach (the codebase
 * uses the hosted-checkout approach). Kept as the contract to switch back to /
 * add an imperative gateway later. Intent must carry SubscriptionSpec (remotePlanId).
 */
interface SupportCreateRemoteSubscription
{
    /**
     * Create the remote subscription. Pure function — no side-effects.
     */
    public function createSubscription(PaymentIntent $intent, PaymentMethodDTO $card): SubscriptionResult;
}
