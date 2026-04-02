<?php

namespace App\Cashier\Contracts;

interface PaymentMethodInfoInterface
{
    public function getAutoBillingData(): array;
}
