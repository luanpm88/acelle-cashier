<?php

namespace App\Cashier\Services;

use App\Cashier\Contracts\IntentGatewayInterface;
use App\Cashier\DTO\CheckoutHandle;
use App\Cashier\DTO\DirectCheckout;
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

    public function __construct(?string $paymentInstruction = '')
    {
        $this->paymentInstruction = $paymentInstruction ?? '';
        $this->active = !empty($this->paymentInstruction);
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
     * IntentGatewayInterface — a DirectCheckout to the offline instructions page (no host-tracked
     * session; the admin-approval flow owns completion).
     */
    public function getCheckoutUrl(PaymentIntent $intent, string $returnUrl, ?string $cancelUrl = null): CheckoutHandle
    {
        return new DirectCheckout(
            action('\App\Cashier\Controllers\OfflineController@checkout', [
                'intent_uid' => $intent->uid,
            ]) . '?return_url=' . urlencode($returnUrl)
        );
    }

    public function getPaymentInstruction(): string
    {
        return $this->paymentInstruction
            ?: trans('cashier::messages.offline.payment_instruction.default');
    }

}
