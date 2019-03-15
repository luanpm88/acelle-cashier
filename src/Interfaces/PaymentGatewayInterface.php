<?php

namespace Acelle\Cashier\Interfaces;

use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\Models\Subscription;

interface PaymentGatewayInterface
{
    public function charge($subscription);
    public function retrieveSubscription($subscriptionId);
    
    public function billableUserHasCard($user);
    public function billableUserUpdateCard($user, $params);
    
    public function cancelSubscription($subscriptionId);    
    public function resumeSubscription($subscriptionId);
    public function cancelNowSubscription($subscriptionId);
    public function swapSubscriptionPlan($subscriptionId, $plan);
    
    public function isSupportRecurring();
}