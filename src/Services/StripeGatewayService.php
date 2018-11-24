<?php

namespace Acelle\Cashier\Services;

use Stripe\Card as StripeCard;
use Stripe\Token as StripeToken;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;

class StripeGatewayService implements PaymentGatewayInterface
{
    public function createSubscription($options = [])
    {
        // Something to do
        
    }
}