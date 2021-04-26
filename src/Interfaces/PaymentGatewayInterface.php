<?php

namespace Acelle\Cashier\Interfaces;

use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\Models\Subscription;

interface PaymentGatewayInterface
{
    // public function check($invoice);
    // public function sync($subscription);
    // public function validate();
    public function canAutoCharge();
    // public function getChangePlanUrl($subscription, $plan_id, $returnUrl='/');
    // public function getRenewUrl($subscription, $returnUrl='/');
    // public function getCheckoutUrl($subscription, $returnUrl='/');
}