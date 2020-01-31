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
    public function charge($subscription) {
        // get or create plan
        $stripeCustomer = $this->getStripeCustomer($subscription->user);

        // Charge customter with current card
        \Stripe\Charge::create([
            'amount' => $this->convertPrice($subscription->plan->getBillableAmount(), $subscription->plan->getBillableCurrency()),
            'currency' => $subscription->plan->getBillableCurrency(),
            'customer' => $stripeCustomer->id,
            'source' => $this->getCardInformation($subscription->user)->id,
            'description' => trans('cashier::messages.transaction.subscribed_to_plan', [
                'plan' => $subscription->plan->getBillableName(),
            ]),
        ]);
    }

    public function sync($subscription) {}

    public function isSupportRecurring() {
        return true;
    }

    public function hasPending($subscription) {
        return false;
    }

    public function getPendingNotice($subscription) {
        return false;
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

    public function changePlan(&$subscription, $newPlan) {
        // calc when change plan
        $result = Cashier::calcChangePlan($subscription, $newPlan);

        // set new amount to plan
        $newPlan->price = $result['amount'];

        // get or create plan
        $stripeCustomer = $this->getStripeCustomer($subscription->user);

        if ($result['amount'] > 0) {
            // Charge customter with current card
            \Stripe\Charge::create([
                'amount' => $this->convertPrice($newPlan->getBillableAmount(), $newPlan->getBillableCurrency()),
                'currency' => $newPlan->getBillableCurrency(),
                'customer' => $stripeCustomer->id,
                'source' => $this->getCardInformation($subscription->user)->id,
                'description' => trans('cashier::messages.transaction.change_plan', [
                    'plan' => $newPlan->getBillableName(),
                ]),
            ]);
        }

        // update subscription date
        $subscription->current_period_ends_at = $result['endsAt'];
        if (isset($subscription->ends_at) && $subscription->ends_at < $result['endsAt']) {
            $subscription->ends_at = $result['endsAt'];
        }
        $subscription->plan_id = $newPlan->getBillableId();
        $subscription->save();

        // add transaction
        $subscription->addTransaction([
            'ends_at' => $subscription->ends_at,
            'current_period_ends_at' => $subscription->current_period_ends_at,
            'status' => SubscriptionTransaction::STATUS_SUCCESS,
            'description' => trans('cashier::messages.transaction.change_plan', [
                'plan' => $newPlan->getBillableName(),
            ]),
            'amount' => $newPlan->getBillableFormattedPrice()
        ]);
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
}