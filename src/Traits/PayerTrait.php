<?php

namespace Acelle\Cashier\Traits;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\Builders\SubscriptionBuilder;

trait PayerTrait
{
    /**
     * The payment gateway service.
     *
     * @var PaymentGatewayInterface
     */
    public $gateway;
    
    /**
     * Begin creating a new subscription.
     *
     * @param  string  $subscription
     * @param  string  $plan
     * @return \Laravel\Cashier\SubscriptionBuilder
     */
    public function newSubscription($plan, PaymentGatewayInterface $gateway)
    {
        return new SubscriptionBuilder($this, $plan, $gateway);
    }
}