<?php

namespace App\Cashier\DTO;

/**
 * Validates and structures Stripe auto-billing data.
 * Used internally by Stripe service to ensure required fields are present.
 */
class StripeAutoBillingData
{
    public string $stripePaymentMethod;
    public string $stripeCustomer;
    public string $cardType;
    public string $last4;
    public int $expMonth;
    public int $expYear;

    public function __construct(array $data)
    {
        foreach (['stripe_payment_method', 'stripe_customer'] as $key) {
            if (empty($data[$key])) {
                throw new \InvalidArgumentException("Missing required Stripe billing data field: {$key}");
            }
        }

        $this->stripePaymentMethod = $data['stripe_payment_method'];
        $this->stripeCustomer = $data['stripe_customer'];
        $this->cardType = $data['card_type'] ?? '';
        $this->last4 = $data['last_4'] ?? '';
        $this->expMonth = (int) ($data['exp_month'] ?? 0);
        $this->expYear = (int) ($data['exp_year'] ?? 0);
    }

    public function toArray(): array
    {
        return [
            'stripe_payment_method' => $this->stripePaymentMethod,
            'stripe_customer' => $this->stripeCustomer,
            'card_type' => $this->cardType,
            'last_4' => $this->last4,
            'exp_month' => $this->expMonth,
            'exp_year' => $this->expYear,
        ];
    }
}
