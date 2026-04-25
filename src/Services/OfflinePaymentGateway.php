<?php

namespace App\Cashier\Services;

use App\Cashier\Contracts\IntentGatewayInterface;
use App\Cashier\DTO\PaymentIntent;

/**
 * Offline payment gateway — manual payment (bank transfer, etc.).
 *
 * User claims intent to pay → admin approves later. No external charge.
 * Status flow: pending → succeeded (after admin approval)
 *           or pending → cancelled (after admin rejection)
 *
 * Implements only IntentGatewayInterface — no auto-charge, no remote subscription.
 * Pure: no DB writes; controller orchestrates side-effects.
 */
class OfflinePaymentGateway implements IntentGatewayInterface
{
    public const TYPE = 'offline';

    private string $paymentInstruction;
    private bool $active;

    public function __construct(string $paymentInstruction = '')
    {
        $this->paymentInstruction = $paymentInstruction;
        $this->active = !empty($paymentInstruction);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    /**
     * IntentGatewayInterface — checkout URL with intent_uid.
     */
    public function getCheckoutUrl(PaymentIntent $intent, string $returnUrl): string
    {
        return action('\App\Cashier\Controllers\OfflineController@checkout', [
            'intent_uid' => $intent->uid,
        ]) . '?return_url=' . urlencode($returnUrl);
    }

    public function getPaymentInstruction(): string
    {
        return $this->paymentInstruction
            ?: trans('cashier::messages.offline.payment_instruction.default');
    }

    /**
     * Display helpers used by main app to render the payment_methods list.
     * Not part of any interface — main app calls via getService() at view render time.
     */
    public function getMethodTitle($billingData): string
    {
        return trans('cashier::messages.offline');
    }

    public function getMethodInfo($billingData): string
    {
        return trans('cashier::messages.offline.description');
    }
}
