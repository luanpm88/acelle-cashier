<?php

namespace App\Cashier\Contracts;

interface PaymentMethodInfoInterface
{
    public function getAutoBillingData(): array;

    /**
     * UID of the PaymentGateway record this payment method is attached to.
     * Needed by the cashier (e.g. when rebuilding 3DS re-auth URLs) so it can
     * resolve the gateway via PaymentGatewayResolverInterface without the main
     * app having to pass credentials.
     */
    public function getPaymentGatewayUid(): string;
}
