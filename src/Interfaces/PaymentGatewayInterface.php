<?php

namespace Acelle\Cashier\Interfaces;

use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\Models\Subscription;

interface PaymentGatewayInterface
{
    public function createSubscription($subscription);
    public function charge($subscription);
    public function retrieveSubscription($subscriptionId);
    
    public function billableUserHasCard($user);
    public function billableUserUpdateCard($user, $params);
    
    public function cancelSubscription($subscriptionId);    
    public function resumeSubscription($subscriptionId);
    public function cancelNowSubscription($subscriptionId);
    public function changePlan($subscriptionId, $plan);
    public function renewSubscription($subscription);
    
    public function isSupportRecurring();
    public function validate();
    
    public function getInvoices($subscriptionId);
    public function getRawInvoices($subscriptionId);
    
    public function checkPendingPaymentForFuture($subscription);
    public function setDone($subscription);
    
    public function approvePendingInvoice($subscription);
}