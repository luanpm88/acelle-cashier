<?php

namespace Acelle\Cashier\Services;

use Illuminate\Support\Facades\Log;
use Stripe\Card as StripeCard;
use Stripe\Token as StripeToken;
use Stripe\Customer as StripeCustomer;
use Stripe\Subscription as StripeSubscription;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\Subscription;
use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\InvoiceParam;
use Carbon\Carbon;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\SubscriptionTransaction;

class StripePaymentGateway implements PaymentGatewayInterface
{
    public $secretKey;
    public $publishableKey;

    /**
     * Construction
     */
    public function __construct($secret_key, $publishable_key)
    {
        $this->secretKey = $secret_key;
        $this->publishableKey = $publishable_key;
        
        \Stripe\Stripe::setApiKey($secret_key);
        \Stripe\Stripe::setApiVersion("2019-12-03");
    }

    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function validate()
    {
        try {
            // Use Stripe's library to make requests...
            $ch = \Stripe\Charge::retrieve(
                "ch_1EFe6rCMj8fc6a7IsF1uWqBW"
            );

            $ch->capture(); // Uses the same API Key.
        } catch(\Stripe\Error\Card $e) {
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
        }
    }

    /**
     * Create a new subscription.
     *
     * @param  Customer                $customer
     * @param  Subscription         $subscription
     * @return void
     */
    public function create($customer, $plan)
    {
        // update subscription model
        $subscription = new Subscription();
        $subscription->user_id = $customer->getBillableId();
        $subscription->plan_id = $plan->getBillableId();
        $subscription->status = Subscription::STATUS_NEW;
        
        $subscription->save();
        
        return $subscription;
    }
    
    /**
     * Charge customer with subscription.
     *
     * @param  Customer                $customer
     * @param  Subscription         $subscription
     * @return void
     */
    public function charge($subscription, $data) {
        // get or create plan
        $stripeCustomer = $this->getStripeCustomer($subscription->user);
        $card = $this->getCardInformation($subscription->user);

        if (!is_object($card)) {
            throw new \Exception('Can not find card information');
        }

        // Charge customter with current card
        \Stripe\Charge::create([
            'amount' => $this->convertPrice($data['amount'], $data['currency']),
            'currency' => $data['currency'],
            'customer' => $stripeCustomer->id,
            'source' => $card->id,
            'description' => $data['description'],
        ]);
    }

    public function sync($subscription) {
        // check recurring
        $this->recurring($subscription);
    }

    public function isSupportRecurring() {
        return true;
    }

    public function getChangePlanUrl($subscription, $plan_id, $returnUrl='/') {
        return action("\Acelle\Cashier\Controllers\StripeController@changePlan", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
            'plan_id' => $plan_id,
        ]);
    }

    public function getRenewUrl($subscription, $returnUrl='/') {
        return false;
    }

    /**
     * Get card information from Stripe user.
     *
     * @param  Subscription    $subscription
     * @return Boolean
     */
    public function getCardInformation($user)
    {
        // get or create plan
        $stripeCustomer = $this->getStripeCustomer($user);

        $cards = $stripeCustomer->sources->all(
            ['object' => 'card']
        );

        return empty($cards->data) ? NULL : $cards->data["0"];
    }

    /**
     * Get the Stripe customer instance for the current user and token.
     *
     * @param  SubscriptionParam    $subscriptionParam
     * @return \Stripe\Customer
     */
    protected function getStripeCustomer($user)
    {
        // Find in gateway server
        $stripeCustomers = \Stripe\Customer::all();
        foreach ($stripeCustomers as $stripeCustomer) {
            if ($stripeCustomer->metadata->local_user_id == $user->getBillableId()) {
                return $stripeCustomer;
            }
        }

        // create if not exist
        $stripeCustomer = \Stripe\Customer::create([
            'email' => $user->getBillableEmail(),
            'metadata' => [
                'local_user_id' => $user->getBillableId(),
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

    /**
     * Recurring charge.
     * 
     * @return void
     */
    public function recurring($subscription=null)
    {
        // check if subscription is null
        if (!$subscription) {
            return;
        }

        // check if has pending transaction
        if (!$subscription->isActive()) {
            return;
        }

        // check if subscription is cancelled
        if ($subscription->cancelled()) {
            return;
        }

        // check if has pending transaction
        if ($this->hasPending($subscription)) {
            return;
        }

        // check if has error transaction
        if ($this->hasError($subscription)) {
            return;
        }

        // check if recurring accur
        if (\Carbon\Carbon::now()->diffInDays($subscription->current_period_ends_at) < 3) {
            // add transaction
            $transaction = $subscription->addTransaction([
                'ends_at' => null,
                'current_period_ends_at' => $subscription->nextPeriod(),
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.recurring_charge', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            // charge
            try {
                $this->charge($subscription, [
                    'amount' => $subscription->plan->getBillableAmount(),
                    'currency' => $subscription->plan->getBillableCurrency(),
                    'description' => trans('cashier::messages.transaction.recurring_charge', [
                        'plan' => $subscription->plan->getBillableName(),
                    ]),
                ]);

                // set active
                $transaction->setSuccess();

                // check new states from transaction
                $subscription->ends_at = $transaction->ends_at;
                $subscription->current_period_ends_at = $transaction->current_period_ends_at;
                $subscription->save();
            } catch (\Exception $e) {
                $transaction->setFailed();

                // update error message
                $transaction->description = $e->getMessage();
                $transaction->save();
            } 
        }
    }

    /**
     * Get last transaction
     *
     * @return boolean
     */
    public function getLastTransaction($subscription) {
        // if has only init transaction
        if ($subscription->subscriptionTransactions()->count() <= 1) {
            return null;
        }

        return $subscription->subscriptionTransactions()->orderBy('created_at', 'desc')->first();
    }

    /**
     * Check if has pending transaction
     *
     * @return boolean
     */
    public function hasPending($subscription) {
        $transaction = $this->getLastTransaction($subscription);
        return isset($transaction) && $transaction->isPending();
    }

    public function getPendingNotice($subscription) {
        return false;
    }

    /**
     * Check if has failed transaction
     *
     * @return boolean
     */
    public function hasError($subscription) {
        $transaction = $this->getLastTransaction($subscription);
        return isset($transaction) && $transaction->isFailed();
    }

    public function getErrorNotice($subscription) {
        $transaction = $this->getLastTransaction($subscription);
        
        return trans('cashier::messages.stripe.payment_error.something_went_wrong', [
            'description' => $transaction->title,
            'amount' => $transaction->amount,
            'url' => action('\Acelle\Cashier\Controllers\StripeController@fixPayment', [
                'subscription_id' => $subscription->uid,
            ]),
        ]);
    }
}