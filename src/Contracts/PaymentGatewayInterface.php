<?php

namespace App\Cashier\Contracts;

use App\Model\Invoice;
use App\Model\Transaction;

/**
 * Legacy contract — used by Offline gateway only after the PaymentIntent refactor.
 * New gateways (Stripe, StripeSubscription) implement IntentGatewayInterface
 * + capability interfaces (SupportsAutoChargeInterface / SupportsSubscriptionInterface).
 */
interface PaymentGatewayInterface
{
    public function getCheckoutUrl(Invoice $invoice, string $paymentGatewayId, string $returnUrl = '/'): string;

    public function supportsAutoBilling(): bool;

    public function autoCharge(Invoice $invoice, PaymentMethodInfoInterface $paymentMethodInfo);

    public function allowManualReviewingOfTransaction(): bool;

    public function getMinimumChargeAmount($currency);

    public function verify(Transaction $transaction);

    public function getMethodTitle($billingData);

    public function getMethodInfo($billingData);
}
