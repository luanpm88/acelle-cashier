<?php

namespace App\Cashier\Contracts;

use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\PaymentResult;
use App\Cashier\DTO\PaymentMethodDTO;

/**
 * Capability: gateway can charge a SAVED card off-session (no card form) — used for
 * one-off charges and recurring auto-charge. The saved card is a {@see PaymentMethodDTO}
 * ((de)serialised generically via the DTO's own toArray()/fromArray(); there is no
 * driver-specific codec). A gateway whose vendor owns the card vault + auto-debits
 * itself (e.g. 2C2P RPP) simply does not implement this interface.
 */
interface SupportsAutoChargeInterface
{
    /**
     * Attempt the off-session charge of a saved card. Pure function — must not mutate DB
     * or invoke handler callbacks.
     */
    public function autoCharge(PaymentIntent $intent, PaymentMethodDTO $pm): PaymentResult;
}
