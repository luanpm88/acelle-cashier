<?php

namespace Acelle\Cashier\Interfaces;

interface PaymentGatewayInterface
{
    public function validate();
    public function supportsAutoBilling();
    public function getCheckoutUrl($invoice, $returnUrl='/');
}