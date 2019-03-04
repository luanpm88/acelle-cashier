<?php

namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\SubscriptionParam;
use Carbon\Carbon;
use Kevupton\LaravelCoinpayments\Coinpayments;

class CoinpaymentsPaymentGateway implements PaymentGatewayInterface
{
    public $coinPaymentsAPI;
    
    // Contruction
    public function __construct($merchantId, $publicKey, $privateKey, $ipnSecret)
    {   
        $this->coinPaymentsAPI = new CoinPayments($privateKey, $publicKey, $merchantId, $ipnSecret, null);
    }
    
    /**
     * Create a new subscriptionParam.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function createSubscription($user, $plan, $subscription)
    {
        // get or create plan
        $stripePlan = $this->getStripePlan($plan);        
        
        // get or create plan
        $stripeCustomer = $this->getStripeCustomer($user);
        
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
        $stripePlans = \Stripe\Plan::all();
        foreach ($stripePlans as $stripePlan) {
            if ($stripePlan->metadata->local_plan_id == $plan->getBillableId()) {
                return $stripePlan;
            }
        }
        
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
        return false;
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
    public function retrieveSubscription($subscriptionId)
    {
        $subscriptionParam = NULL;
        
        // get stripe subscription
        $stripeSubscription = $this->getStripeSubscription($subscriptionId);
            
        if ($stripeSubscription != NULL) {
            
            $subscriptionParam = new SubscriptionParam([
                'currentPeriodEnd' => $stripeSubscription->current_period_end,
                'createdAt' => $stripeSubscription->created,
            ]);
            
            // ends at
            if ($stripeSubscription->cancel_at && $stripeSubscription->current_period_end) {
                $subscriptionParam->endsAt = $stripeSubscription->current_period_end;
            }
            
            // ended
            if ($stripeSubscription->ended_at) {
                $subscriptionParam->endsAt = $stripeSubscription->ended_at;
            }
            
            // update plan
            $subscriptionParam->planId = $stripeSubscription->plan->metadata->local_plan_id;            
        }
        
        return $subscriptionParam;
    }
    
    /**
     * Cancel subscription.
     *
     * @param  Subscription  $subscription
     * @return [$currentPeriodEnd]
     */
    public function cancelSubscription($subscriptionId)
    {
        $stripeSubscription = $this->getStripeSubscription($subscriptionId);        
        $stripeSubscription->cancel_at_period_end = true;
        $stripeSubscription->save();
    }
    
    /**
     * Resume subscription.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function resumeSubscription($subscriptionId)
    {
        $stripeSubscription = $this->getStripeSubscription($subscriptionId);        
        $stripeSubscription->cancel_at_period_end = false;

        // To resume the subscription we need to set the plan parameter on the Stripe
        // subscription object. This will force Stripe to resume this subscription
        // where we left off. Then, we'll set the proper trial ending timestamp.
        $stripeSubscription->plan = $stripeSubscription->plan->id;

        $stripeSubscription->save();
    }
    
    /**
     * Resume subscription.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function cancelNowSubscription($subscriptionId)
    {
        $stripeSubscription = $this->getStripeSubscription($subscriptionId);        
        $stripeSubscription->cancel();
    }
    
    /**
     * Swap subscription plan.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function swapSubscriptionPlan($subscriptionId, $plan)
    {
        $stripeSubscription = $this->getStripeSubscription($subscriptionId);    

        $stripePlan = $this->getStripePlan($plan);
        
        $stripeSubscription->plan = $stripePlan;
        
        $stripeSubscription->cancel_at_period_end = false;
        
        $stripeSubscription->save();
    }
}