<?php

namespace App\Cashier\DTO;

/**
 * Snapshot of payer identity + billing context, taken when a PaymentIntent is built.
 * Immutable. Cashier never queries the local Customer/Invoice — everything it needs is here.
 */
class Payer
{
    public function __construct(
        public readonly string $uid,
        public readonly string $name,
        public readonly string $email,
        public readonly string $phone = '',
        public readonly string $billingName = '',
        public readonly string $billingAddress = '',
        public readonly string $billingCountryCode = '',
    ) {}
}
