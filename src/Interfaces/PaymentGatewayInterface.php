<?php

namespace Acelle\Cashier\Interfaces;

use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\Models\Subscription;

interface PaymentGatewayInterface
{
    public function createSubscription($user, $plan, $subscription);
    public function retrieveSubscription($remoteSubscriptionId);
    
    public function billableUserHasCard($gateway); //... ---> 
}