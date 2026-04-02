<?php

namespace App\Cashier\DTO;

/**
 * Validates and structures Stripe auto-billing data.
 * Used internally by Stripe service to ensure required fields are present.
 */
class StripeAutoBillingData
{
    public string $paymentMethodId;
    public string $customerId;
    public string $cardType;
    public string $last4;
    public int $expMonth;
    public int $expYear;

    public function __construct(array $data)
    {
        foreach (['payment_method_id', 'customer_id'] as $key) {
            if (empty($data[$key])) {
                throw new \InvalidArgumentException("Missing required Stripe billing data field: {$key}");
            }
        }

        $this->paymentMethodId = $data['payment_method_id'];
        $this->customerId = $data['customer_id'];
        $this->cardType = $data['card_type'] ?? '';
        $this->last4 = $data['last_4'] ?? '';
        $this->expMonth = (int) ($data['exp_month'] ?? 0);
        $this->expYear = (int) ($data['exp_year'] ?? 0);
    }

    public function toArray(): array
    {
        return [
            'payment_method_id' => $this->paymentMethodId,
            'customer_id' => $this->customerId,
            'card_type' => $this->cardType,
            'last_4' => $this->last4,
            'exp_month' => $this->expMonth,
            'exp_year' => $this->expYear,
        ];
    }
}
