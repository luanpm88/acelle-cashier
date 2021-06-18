<?php

namespace Acelle\Cashier\Services;

use Illuminate\Support\Facades\Log;
use Stripe\Card as StripeCard;
use Stripe\Token as StripeToken;
use Stripe\Customer as StripeCustomer;
use Stripe\Subscription as StripeSubscription;
use Acelle\Library\Contracts\PaymentGatewayInterface;
use Carbon\Carbon;
use Acelle\Cashier\Cashier;
use Acelle\Library\AutoBillingData;
use Acelle\Model\Invoice;
use Acelle\Library\TransactionVerificationResult;
use Acelle\Model\Transaction;

class StripePaymentGateway implements PaymentGatewayInterface
{
    protected $secretKey;
    protected $publishableKey;
    protected $active = false;

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

    public function getName() : string
    {
        return 'Stripe';
    }

    public function getType() : string
    {
        return 'stripe';
    }

    public function getDescription() : string
    {
        return 'Receive payments from Credit / Debit card to your Stripe account';
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

    public function getSettingsUrl() : string
    {
        return action("\Acelle\Cashier\Controllers\StripeController@settings");
    }

    public function getAutoBillingDataUpdateUrl($returnUrl='/') : string
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\StripeController@autoBillingDataUpdate", [
            'return_url' => $returnUrl,
        ]);
    }

    public function supportsAutoBilling() : bool
    {
        return true;
    }

    public function verify(Transaction $transaction) : TransactionVerificationResult
    {
        return new TransactionVerificationResult(TransactionVerificationResult::RESULT_VERIFICATION_NOT_NEEDED);
    }

    public function allowManualReviewingOfTransaction() : bool
    {
        return false;
    }

    /**
     * Check invoice for paying.
     *
     * @return void
     */
    public function autoCharge($invoice)
    {
        $gateway = $this;

        $invoice->checkout($this, function($invoice) use ($gateway) {
            $autoBillingData = $invoice->customer->getAutoBillingData();

            try {
                // charge invoice
                $gateway->doCharge([
                    'customer' => $autoBillingData->getData()['customer'],
                    'source' => $autoBillingData->getData()['source'],
                    'amount' => $invoice->total(),
                    'currency' => $invoice->currency->code,
                    'description' => trans('messages.pay_invoice', [
                        'id' => $invoice->uid,
                    ]),
                ]);

                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_DONE);
            } catch (\Stripe\Exception\CardException $e) {
                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_FAILED, $e->getError()->message);
            } catch (\Exception $e) {
                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_FAILED, $e->getMessage());
            }
        });
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
     * Charge customer with subscription.
     *
     * @param  Customer                $customer
     * @return void
     */
    public function doCharge($data)
    {
        // Charge customter with current card
        \Stripe\Charge::create([
            'amount' => $this->convertPrice($data['amount'], $data['currency']),
            'currency' => $data['currency'],
            'customer' => $data['customer'],
            'source' => $data['source'],
            'description' => $data['description'],
        ]);
    }

    /**
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($invoice) : string
    {
        return action("\Acelle\Cashier\Controllers\StripeController@checkout", [
            'invoice_uid' => $invoice->uid,
        ]);
    }

    /**
     * Get user has card.
     *
     * @return string
     */
    public function hasCard($customer)
    {
        return is_object($this->getCardInformation($customer));
    }

    /**
     * Get card information from Stripe user.
     *
     * @param  User    $user
     * @return Boolean
     */
    public function getCardInformation($user)
    {
        // get or create plan
        $stripeCustomer = $this->getStripeCustomer($user);

        $cards = $stripeCustomer->sources->all(
            ['object' => 'card']
        );

        return empty($cards->data) ? null : $cards->data["0"];
    }

    /**
     * Get the Stripe customer instance for the current user and token.
     *
     * @param  User    $user
     * @return \Stripe\Customer
     */
    public function getStripeCustomer($user)
    {
        // Find in gateway server
        $stripeCustomers = \Stripe\Customer::all();
        foreach ($stripeCustomers as $stripeCustomer) {
            if ($stripeCustomer->metadata->local_user_id == $user->uid) {
                return $stripeCustomer;
            }
        }

        // create if not exist
        $stripeCustomer = \Stripe\Customer::create([
            'email' => $user->user->email,
            'metadata' => [
                'local_user_id' => $user->uid,
            ],
        ]);

        return $stripeCustomer;
    }

    /**
     * Update user card.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function billableUserUpdateCard($user, $params)
    {
        $stripeCustomer = $this->getStripeCustomer($user);

        $card = $stripeCustomer->sources->create(['source' => $params['stripeToken']]);
        
        $stripeCustomer->default_source = $card->id;
        $stripeCustomer->save();

        return $card;
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
}
