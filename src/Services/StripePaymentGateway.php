<?php

namespace App\Cashier\Services;

use App\Cashier\Contracts\IntentGatewayInterface;
use App\Cashier\Contracts\SupportsAutoChargeInterface;
use App\Cashier\DTO\PaymentIntent;
use App\Cashier\DTO\PaymentResult;

/**
 * Stripe one-off payment gateway.
 *
 * Implements:
 * - IntentGatewayInterface  → consumes PaymentIntent DTO at checkout
 * - SupportsAutoChargeInterface  → charges card off-session, returns pure PaymentResult
 *
 * Pure: no DB writes, no handler callbacks. Controller orchestrates side-effects.
 */
class StripePaymentGateway implements IntentGatewayInterface, SupportsAutoChargeInterface
{
    public const TYPE = 'stripe';

    protected $secretKey;
    protected $publishableKey;
    protected $active = false;

    public function __construct($publishableKey, $secretKey)
    {
        $this->publishableKey = $publishableKey;
        $this->secretKey = $secretKey;

        $this->validate();

        \Stripe\Stripe::setApiKey($this->secretKey);
        \Stripe\Stripe::setApiVersion("2019-12-03");

        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    public function validate()
    {
        $this->active = ($this->publishableKey && $this->secretKey);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getSecretKey()
    {
        return $this->secretKey;
    }

    public function getPublishableKey()
    {
        return $this->publishableKey;
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    /**
     * IntentGatewayInterface — build the checkout URL the user is redirected to.
     */
    public function getCheckoutUrl(PaymentIntent $intent, string $returnUrl): string
    {
        return action('\App\Cashier\Controllers\StripeController@checkout', [
            'intent_uid' => $intent->uid,
        ]) . '?return_url=' . urlencode($returnUrl);
    }

    /**
     * SupportsAutoChargeInterface — attempt off-session charge. PURE.
     *
     * @param array $pmData StripeAutoBillingData::toArray() — must contain stripe_customer + stripe_payment_method
     */
    public function autoCharge(PaymentIntent $intent, array $pmData): PaymentResult
    {
        // Free invoice: skip Stripe call. Caller dispatches success.
        if ($intent->amount <= 0) {
            return PaymentResult::success('FREE_NO_CHARGE');
        }

        try {
            $pi = \Stripe\PaymentIntent::create([
                'amount'         => $this->convertPrice($intent->amount, $intent->currency),
                'currency'       => strtolower($intent->currency),
                'customer'       => $pmData['stripe_customer'] ?? null,
                'payment_method' => $pmData['stripe_payment_method'] ?? null,
                'off_session'    => true,
                'confirm'        => true,
                'description'    => $intent->description,
                'metadata'       => ['intent_uid' => $intent->uid],
            ]);

            if ($pi->status === 'succeeded') {
                return PaymentResult::success($pi->id);
            }

            if ($pi->status === 'requires_action') {
                return PaymentResult::requiresAuth(
                    clientSecret: $pi->client_secret,
                    remoteRef: $pi->id
                );
            }

            return PaymentResult::failed("Unexpected PaymentIntent status: {$pi->status}", $pi->id);
        } catch (\Stripe\Exception\CardException $e) {
            $remotePi = $e->getError()->payment_intent ?? null;

            if ($remotePi && ($remotePi->status ?? null) === 'requires_action') {
                return PaymentResult::requiresAuth(
                    clientSecret: $remotePi->client_secret,
                    remoteRef: $remotePi->id
                );
            }

            return PaymentResult::failed($e->getMessage(), $remotePi?->id);
        } catch (\Throwable $e) {
            return PaymentResult::failed($e->getMessage());
        }
    }

    /**
     * Health check on credentials.
     */
    public function test()
    {
        try {
            \Stripe\Customer::all(['limit' => 1]);
        } catch (\Throwable $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Get user has card.
     */
    public function hasCard($customerUid)
    {
        return is_object($this->getCardInformation($customerUid));
    }

    /**
     * Get card information from Stripe customer.
     */
    public function getCardInformation($customerUid)
    {
        $stripeCustomer = $this->getStripeCustomer($customerUid);

        $cards = \Stripe\PaymentMethod::all([
            'customer' => $stripeCustomer->id,
            'type' => 'card',
        ]);

        return empty($cards->data) ? null : $cards->data[0];
    }

    /**
     * Find a Stripe Customer by local payer UID, or create one.
     */
    public function getStripeCustomer($customerUid)
    {
        $stripeCustomers = \Stripe\Customer::all();
        foreach ($stripeCustomers as $stripeCustomer) {
            if (($stripeCustomer->metadata->local_user_id ?? null) == $customerUid) {
                return $stripeCustomer;
            }
        }

        return \Stripe\Customer::create([
            'metadata' => ['local_user_id' => $customerUid],
        ]);
    }

    /**
     * Currency-specific divisor (Stripe wants amount in smallest unit; some are zero-decimal).
     */
    public function currencyRates()
    {
        return [
            'CLP' => 1, 'DJF' => 1, 'JPY' => 1, 'KMF' => 1, 'RWF' => 1,
            'VUV' => 1, 'XAF' => 1, 'XOF' => 1, 'BIF' => 1, 'GNF' => 1,
            'KRW' => 1, 'MGA' => 1, 'PYG' => 1, 'VND' => 1, 'XPF' => 1,
        ];
    }

    public function convertPrice($price, $currency)
    {
        $rate = $this->currencyRates()[$currency] ?? 100;
        return (int) round($price * $rate);
    }

    public function revertPrice($price, $currency)
    {
        $rate = $this->currencyRates()[$currency] ?? 100;
        return $price / $rate;
    }

    /**
     * Create a SetupIntent and return its client_secret for stripe.confirmCardSetup() in JS.
     */
    public function getClientSecret($customerUid)
    {
        $stripeCustomer = $this->getStripeCustomer($customerUid);

        $intent = \Stripe\SetupIntent::create([
            'customer' => $stripeCustomer->id,
            'usage'    => 'off_session',
        ]);

        return $intent->client_secret;
    }

    public function getPaymentMethod($paymentMethodId)
    {
        return \Stripe\PaymentMethod::retrieve($paymentMethodId);
    }

    public function getMinimumChargeAmount($currency)
    {
        return 0;
    }

    /**
     * Display helpers used by main app to render payment_methods table.
     * Kept as concrete methods (no interface) — main app calls via getService().
     */
    public function getMethodTitle(array $billingData): string
    {
        return $billingData['card_type'] ?? 'Unknown';
    }

    public function getMethodInfo(array $billingData): string
    {
        return "*** *** *** " . ($billingData['last_4'] ?? 'Unknown');
    }

    public function supportsAutoBilling(): bool
    {
        return true;
    }
}
