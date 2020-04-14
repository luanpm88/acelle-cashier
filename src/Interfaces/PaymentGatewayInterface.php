<?php

namespace Acelle\Cashier\Interfaces;

use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\Models\Subscription;

interface PaymentGatewayInterface
{
    public function create($customer, $plan);
    public function sync($subscription);
    public function validate();
    public function isSupportRecurring();
    public function getChangePlanUrl($subscription, $plan_id, $returnUrl='/');
    public function getRenewUrl($subscription, $returnUrl='/');
}