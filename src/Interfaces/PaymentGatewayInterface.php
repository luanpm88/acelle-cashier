<?php

namespace Acelle\Cashier\Interfaces;

interface PaymentGatewayInterface
{
    // public function check($invoice);
    // public function sync($subscription);
    // public function validate();
    public function supportsAutoBilling();
    // public function getChangePlanUrl($subscription, $plan_id, $returnUrl='/');
    // public function getRenewUrl($subscription, $returnUrl='/');
    // public function getCheckoutUrl($subscription, $returnUrl='/');
}