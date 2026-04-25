<?php

namespace App\Cashier\Contracts;

interface PaymentGatewayResolverInterface
{
    /**
     * Resolve a payment gateway UID (chosen by user at checkout UI) into a
     * ready-to-use IntentGatewayInterface with credentials already loaded.
     *
     * Returns null if the UID does not match any configured gateway.
     */
    public function resolve(string $paymentGatewayUid): ?IntentGatewayInterface;
}
