<?php

namespace App\Cashier\Contracts;

use App\Cashier\Contracts\PaymentGatewayInterface;

interface PaymentGatewayResolverInterface
{
    /**
     * Resolve a payment gateway UID (chosen by user at checkout UI)
     * into a ready-to-use PaymentGatewayInterface with credentials already loaded.
     *
     * Returns null if the UID does not match any configured gateway.
     */
    public function resolve(string $paymentGatewayUid): ?PaymentGatewayInterface;
}
