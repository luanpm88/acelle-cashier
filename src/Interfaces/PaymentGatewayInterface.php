<?php

namespace Acelle\Cashier\Interfaces;

use Acelle\Model\Invoice;
use Acelle\Model\Transaction;
use Acelle\Cashier\Library\TransactionVerificationResult;

interface PaymentGatewayInterface
{
    // Basic information of the related payment method
    public function getName(): string;
    public function getType(): string;
    public function getDescription(): string;
    public function getShortDescription(): string;

    // Whether or not the payment service is currently available
    public function isActive(): bool;

    // Every payment gateway plugin has its own setting page,
    // For example, the setting page for Stripe is the place where the admin enter the Stripe API key
    public function getSettingsUrl(): string;

    // Some payment gateway plugin has its own page for handling the payment process
    // For example, Stripe will redirect users to the check pages in which users can enter their credit/debit card information
    public function getCheckoutUrl(Invoice $invoice): string;

    // Check if a payment gateway supports auto billing
    // i.e. Stripe allows users to enter their credit/debit cards to the Stripe service
    // which is uniquely identified by a Token
    // The application can stores the token and use it to automatically charge the related card
    public function supportsAutoBilling(): bool;

    // Charge an invoice in the background
    // This method is executed in the background
    public function autoCharge(Invoice $invoice); // dành cho cronjob của core gọi

    // In certain cases, users will need to update their payment information (credit card numbers, etc.)
    public function getAutoBillingDataUpdateUrl(): string;
    public function verify(Transaction $transaction): TransactionVerificationResult;

    //
    public function allowManualReviewingOfTransaction(): bool;
    public function getMinimumChargeAmount($currency);
}
