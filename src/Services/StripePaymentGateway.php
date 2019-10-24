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

class StripePaymentGateway implements PaymentGatewayInterface
{
    public $subscriptionParam;
    public $owner;
    public $plan;
    public $cardToken;
    public $secretKey;
    public $publishableKey;

    public function __construct($secret_key, $publishable_key)
    {
        $this->secretKey = $secret_key;
        $this->publishableKey = $publishable_key;
        
        \Stripe\Stripe::setApiKey($secret_key);
        \Stripe\Stripe::setApiVersion("2017-04-06");
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
     * Check if support recurring.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function isSupportRecurring()
    {
        return true;
    }

    /**
     * Create a new subscription.
     *
     * @param  mixed                $token
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
     * Create a new subscriptionParam.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function charge($subscription)
    {
        // get or create plan
        $stripePlan = $this->getStripePlan($subscription->plan);

        // get or create plan
        $stripeCustomer = $this->getStripeCustomer($subscription->user);

        // create subscription
        $stripeSubscription = \Stripe\Subscription::create([
            "customer" => $stripeCustomer->id,
            "items" => [
                [
                    "plan" => $stripePlan->id,
                ],
            ],
            'metadata' => [
                'local_subscription_id' => $subscription->uid,
            ],
        ]);
        
        $this->sync($subscription);
    }

    /**
     * Get the Stripe plan instance for the current user and token.
     *
     * @param  string|null          $token
     * @param  SubscriptionParam    $subscriptionParam
     * @return \Stripe\Customer
     */
    protected function getStripePlan($plan)
    {
        //$stripePlans = \Stripe\Plan::all();
        //foreach ($stripePlans as $stripePlan) {
        //    if ($stripePlan->metadata->local_plan_id == $plan->getBillableId()) {
        //        return $stripePlan;
        //    }
        //}

        // if plan dosen't exist
        $stripePlan = \Stripe\Plan::create([
            'name' => $plan->getBillableName(),
            'interval' => $plan->getBillableInterval(),
            'interval_count' => $plan->getBillableIntervalCount(),
            'currency' => $plan->getBillableCurrency(),
            'amount' => $this->convertPrice($plan->getBillableAmount(), $plan->getBillableCurrency()),
            'metadata' => [
                'local_plan_id' => $plan->getBillableId(),
            ],
        ]);

        return $stripePlan;
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
     * Check if customer has valid card.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function billableUserHasCard($user)
    {
        $stripeCustomer = $this->getStripeCustomer($user);

        // get card from customer
        return isset($stripeCustomer->default_source);
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
     * Get Stripe Subscription.
     *
     * @param  Subscription  $subscription
     * @return SubscriptionParam
     */
    public function getStripeSubscription($subscriptionId)
    {
        // Find in gateway server
        $stripeSubscriptions = \Stripe\Subscription::all();
        foreach ($stripeSubscriptions as $stripeSubscription) {
            if ($stripeSubscription->metadata->local_subscription_id == $subscriptionId) {
                return $stripeSubscription;
            }
        }

        // Find cancelled subscription
        $stripeSubscriptions = \Stripe\Subscription::all(["status" => "canceled"]);
        foreach ($stripeSubscriptions as $stripeSubscription) {
            if ($stripeSubscription->metadata->local_subscription_id == $subscriptionId) {
                return $stripeSubscription;
            }
        }

        return NULL;
    }

    /**
     * Retrieve subscription param.
     *
     * @param  Subscription  $subscription
     * @return SubscriptionParam
     */
    public function sync($subscription)
    {
        // get stripe subscription
        $stripeSubscription = $this->getStripeSubscription($subscription->uid);
        
        // default is active
        $subscription->status = Subscription::STATUS_ACTIVE;
        
        // Can not find strip subscription
        if ($stripeSubscription == NULL) {
            throw new \Exception('Stripe subscription can not be found');
        }
        
        // Current period ends at
        if ($stripeSubscription->current_period_end) {
            $subscription->current_period_ends_at = Carbon::createFromTimestamp($stripeSubscription->current_period_end);
        }
        
        // ends at
        $subscription->ends_at = null;
        if ($stripeSubscription->cancel_at && $stripeSubscription->current_period_end) {
            $subscription->ends_at = Carbon::createFromTimestamp($stripeSubscription->current_period_end);
        }

        // ended
        if ($stripeSubscription->ended_at) {
            $subscription->ends_at = Carbon::createFromTimestamp($stripeSubscription->ended_at);
            $subscription->status = Subscription::STATUS_ENDED;
        }

        // update plan
        $subscription->plan_id = $stripeSubscription->plan->metadata->local_plan_id;
        
        $subscription->save();        
        return $subscription;
    }

    /**
     * Cancel subscription.
     *
     * @param  Subscription  $subscription
     * @return [$currentPeriodEnd]
     */
    public function cancel($subscription)
    {
        $stripeSubscription = $this->getStripeSubscription($subscription->uid);
        $stripeSubscription->cancel_at_period_end = true;
        $stripeSubscription->save();
        
        // sync
        $this->sync($subscription);
    }

    /**
     * Resume subscription.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function resume($subscription)
    {
        $stripeSubscription = $this->getStripeSubscription($subscription->uid);
        $stripeSubscription->cancel_at_period_end = false;

        // To resume the subscription we need to set the plan parameter on the Stripe
        // subscription object. This will force Stripe to resume this subscription
        // where we left off. Then, we'll set the proper trial ending timestamp.
        $stripeSubscription->plan = $stripeSubscription->plan->id;
        $stripeSubscription->save();
        
        // sync
        $this->sync($subscription);
    }

    /**
     * Resume subscription.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function cancelNow($subscription)
    {
        $stripeSubscription = $this->getStripeSubscription($subscription->uid);
        
        $stripeSubscription->cancel();
        
        // sync
        $this->sync($subscription);
    }

    /**
     * Renew subscription.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function renewSubscription($subscription)
    {

    }

    /**
     * Swap subscription plan.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function changePlan($subscription, $plan)
    {
        $stripeSubscription = $this->getStripeSubscription($subscription->uid);

        $stripePlan = $this->getStripePlan($plan);

        $stripeSubscription->plan = $stripePlan;

        $stripeSubscription->cancel_at_period_end = false;

        $stripeSubscription->save();
        
        try {
            // invoice at once
            \Stripe\Invoice::create([
                "customer" => $stripeSubscription->customer,
                "subscription" => $stripeSubscription->id,
            ]);
        } catch(\Exception $e) {
            Log::error('Can not invoice at once when changing Stripe Plan: '.$e->getMessage());
        }

        return $subscription;
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

        return $price * $rate;
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
     * Get subscription invoices.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function getInvoices($subscription)
    {
        $result = [];

        $stripeSubscription = $this->getStripeSubscription($subscription->uid);
        $invoices = \Stripe\Invoice::all(["subscription" => $stripeSubscription->id]);

        foreach($invoices["data"] as $invoice) {
            $result[] = new InvoiceParam([
                'createdAt' => $invoice->period_start,
                'periodEndsAt' => $invoice->lines->data[0]["period"]["end"],
                'amount' => $this->revertPrice($invoice->amount_due, strtoupper($invoice->currency)) . ' ' . strtoupper($invoice->currency),
                'description' => trans('cashier::messages.stripe.invoice.' . $invoice->billing_reason),
                'status' => $invoice->status
            ]);
        }

        return $result;
    }

    /**
     * Top-up subscription.
     *
     * @param  Subscription    $subscription
     * @return Boolean
     */
    public function topUp($subscription)
    {
        return false;
    }

    /**
     * Get subscription raw invoices.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function getRawInvoices($subscription)
    {
        $result = [];

        $stripeSubscription = $this->getStripeSubscription($subscription->uid);
        $invoices = \Stripe\Invoice::all(["subscription" => $stripeSubscription->id]);

        return $invoices["data"];
    }

    /**
     * Allow admin update payment status without service without payment.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function setActive($subscription)
    {
        throw new \Exception('The Payment service dose not support this feature!');
    }

    /**
     * Approve future invoice
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function approvePendingInvoice($subscription)
    {
        throw new \Exception('The Payment service dose not support this feature!');
    }

    /**
     * Check if subscription has future payment pending.
     *
     * @param  Subscription    $subscription
     * @return Boolean
     */
    public function checkPendingPaymentForFuture($subscription)
    {
    }

    /**
     * Check if subscription has future payment pending.
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
     * Get renew url.
     *
     * @return string
     */
    public function getRenewUrl($subscription, $returnUrl='/')
    {
        return action("\Acelle\Cashier\Controllers\\StripeController@renew", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }
    
    /**
     * Get renew url.
     *
     * @return string
     */
    public function getChangePlanUrl($subscription, $plan_id, $returnUrl='/')
    {
        return action("\Acelle\Cashier\Controllers\\StripeController@changePlan", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
            'plan_id' => $plan_id,
        ]);
    }
    
    /**
     * Get renew url.
     *
     * @return string
     */
    public function getPendingUrl($subscription, $returnUrl='/')
    {
        return action("\Acelle\Cashier\Controllers\\StripeController@pending", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }
    
    /**
     * Check for notice.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function hasPending($subscription)
    {
        return false;
    }
}