<?php

namespace Acelle\Cashier\Services;

use Acelle\Library\Contracts\PaymentGatewayInterface;
use Acelle\Model\Transaction;
use Acelle\Model\PaymentMethod;

class StripePaymentGateway implements PaymentGatewayInterface
{
    protected $secretKey;
    protected $publishableKey;
    protected $active = false;

    public const TYPE = 'stripe';

    /**
     * Construction
     */
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
        if (!$this->publishableKey || !$this->secretKey) {
            $this->active = false;
        } else {
            $this->active = true;
        }
    }

    public function isActive() : bool
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

    public function supportsAutoBilling() : bool
    {
        return true;
    }

    public function verify(Transaction $transaction)
    {
        throw new \Exception("Payment service {$this->getType()} should not have pending transaction to verify");
    }

    public function allowManualReviewingOfTransaction() : bool
    {
        return false;
    }

    public function autoCharge($invoice, PaymentMethod $paymentMethod)
    {
        try {
            // charge invoice
            $autobillingData = json_decode($paymentMethod->autobilling_data, true);

            \Stripe\PaymentIntent::create([
                'amount' => $this->convertPrice($invoice->total(), $invoice->getCurrencyCode()),
                'currency' => $invoice->getCurrencyCode(),
                'customer' => $autobillingData['customer_id'],
                'payment_method' => $autobillingData['payment_method_id'],
                'off_session' => true,
                'confirm' => true,
                'description' => trans('messages.pay_invoice', [
                    'id' => $invoice->uid,
                ]),
            ]);

            // success
            $invoice->paySuccess($paymentMethod);
        } catch (\Stripe\Exception\CardException $e) {
            // Error code will be authentication_required if authentication is needed
            $payment_intent_id = $e->getError()->payment_intent->id;
            // $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);

            $authPaymentLink = action("\Acelle\Cashier\Controllers\StripeController@paymentAuth", [
                'invoice_uid' => $invoice->uid,
                'payment_gateway_id' => $paymentMethod->paymentGateway->uid,
            ]);

            // failed
            $invoice->payFailed(
                $paymentMethod, 
                $e->getError()->message . ' ' . trans('cashier::messages.stripe.click_to_auth', [
                    'link' => $authPaymentLink,
                ])    
            );
        } catch (\Throwable $e) {
            $authPaymentLink = action("\Acelle\Cashier\Controllers\StripeController@paymentAuth", [
                'invoice_uid' => $invoice->uid,
                'payment_gateway_id' => $paymentMethod->paymentGateway->uid,
            ]);

            // failed
            $invoice->payFailed(
                $paymentMethod, 
                $e->getMessage() . ' ' . trans('cashier::messages.stripe.click_to_auth', [
                    'link' => $authPaymentLink,
                ]) 
            );
        }
    }

    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function test()
    {
        try {
            \Stripe\Customer::all(['limit' => 1]);
        } catch (\Stripe\Error\Card $e) {
            // Since it's a decline, \Stripe\Error\Card will be caught
        } catch (\Stripe\Error\RateLimit $e) {
            // Too many requests made to the API too quickly
        } catch (\Stripe\Error\InvalidRequest $e) {
            // Invalid parameters were supplied to Stripe's API
        } catch (\Stripe\Error\Authentication $e) {
            // Authentication with Stripe's API failed
            // (maybe you changed API keys recently)
            throw new \Stripe\Error\Authentication($e->getMessage());
        } catch (\Stripe\Error\ApiConnection $e) {
            // Network communication with Stripe failed
        } catch (\Stripe\Error\Base $e) {
            // Display a very generic error to the user, and maybe send
            // yourself an email
        } catch (Exception $e) {
            // Something else happened, completely unrelated to Stripe
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($invoice, $paymentGatewayId) : string
    {
        return action("\Acelle\Cashier\Controllers\StripeController@checkout", [
            'invoice_uid' => $invoice->uid,
            'payment_gateway_id' => $paymentGatewayId,
        ]);
    }

    /**
     * Get user has card.
     *
     * @return string
     */
    public function hasCard($customerUid)
    {
        return is_object($this->getCardInformation($customerUid));
    }

    /**
     * Get card information from Stripe user.
     *
     * @param  User    $user
     * @return Boolean
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
     * Get the Stripe customer instance for the current user and token.
     *
     * @param  User    $user
     * @return \Stripe\Customer
     */
    public function getStripeCustomer($customerUid)
    {
        // Find in gateway server
        $stripeCustomers = \Stripe\Customer::all();
        foreach ($stripeCustomers as $stripeCustomer) {
            if ($stripeCustomer->metadata->local_user_id == $customerUid) {
                return $stripeCustomer;
            }
        }

        // create if not exist
        $stripeCustomer = \Stripe\Customer::create([
            'metadata' => [
                'local_user_id' => $customerUid,
            ],
        ]);

        return $stripeCustomer;
    }

    /**
     * Current rate for convert/revert Stripe price.
     *
     * @param  mixed    $price
     * @param  string    $currency
     * @return integer
     */
    public function currencyRates()
    {
        return [
            'CLP' => 1,
            'DJF' => 1,
            'JPY' => 1,
            'KMF' => 1,
            'RWF' => 1,
            'VUV' => 1,
            'XAF' => 1,
            'XOF' => 1,
            'BIF' => 1,
            'GNF' => 1,
            'KRW' => 1,
            'MGA' => 1,
            'PYG' => 1,
            'VND' => 1,
            'XPF' => 1,
        ];
    }

    /**
     * Convert price to Stripe price.
     *
     * @param  mixed    $price
     * @param  string    $currency
     * @return integer
     */
    public function convertPrice($price, $currency)
    {
        $currencyRates = $this->currencyRates();

        $rate = isset($currencyRates[$currency]) ? $currencyRates[$currency] : 100;

        return round($price * $rate);
    }

    /**
     * Revert price from Stripe price.
     *
     * @param  mixed    $price
     * @param  string    $currency
     * @return integer
     */
    public function revertPrice($price, $currency)
    {
        $currencyRates = $this->currencyRates();

        $rate = isset($currencyRates[$currency]) ? $currencyRates[$currency] : 100;

        return $price / $rate;
    }

    public function getClientSecret($customerUid)
    {
        $stripeCustomer = $this->getStripeCustomer($customerUid);

        $intent = \Stripe\SetupIntent::create([
            'customer' => $stripeCustomer->id,
            'usage' => 'off_session',
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

    // get method title
    public function getMethodTitle($billingData)
    {
        return $billingData['card_type'] ?? 'Unknown';
    }

    // get method info
    public function getMethodInfo($billingData)
    {
        return "*** *** *** " . ($billingData['last_4'] ?? 'Unknown');
    }
}
