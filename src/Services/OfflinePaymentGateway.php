<?php

namespace App\Cashier\Services;

use App\Cashier\Contracts\IntentGatewayInterface;
use App\Cashier\Contracts\SupportsDiscounts;
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
 * Implements IntentGatewayInterface — no auto-charge, no remote subscription.
 *
 * ALSO implements SupportsDiscounts (a pure marker): unlike a card gateway that
 * realizes a coupon inside its own checkout, offline "realizes" a discount PURELY
 * LOCALLY — there is no external charge to reduce. The host stamps the coupon refs
 * (coupon_id / coupon_customer_id / coupon_amount + the DiscountSpec) onto the pending
 * intent's metadata at hand-off; those survive claim → admin-approval untouched, and
 * FulfillmentService::completeInvoice reads coupon_amount off the succeeded intent to
 * record invoices.discount_amount + the net amount_paid. The offline instructions page
 * shows the NET (amount − coupon_amount) so the buyer transfers the discounted sum. The
 * DiscountSpec itself is never consumed here (offline never charges) — the net is the
 * app's already-resolved coupon_amount. Without this marker BillingManager refuses to
 * pass the discount and offline would silently sell at full price.
 * Pure: no DB writes; controller orchestrates side-effects.
 */
class OfflinePaymentGateway implements IntentGatewayInterface, SupportsDiscounts
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
