<?php

namespace Acelle\Cashier\Services;

use Stripe\Card as StripeCard;
use Stripe\Token as StripeToken;
use Stripe\Customer as StripeCustomer;
use Stripe\Subscription as StripeSubscription;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\SubscriptionParam;
use Carbon\Carbon;

class StripePaymentGateway implements PaymentGatewayInterface
{
    public $subscriptionParam;
    public $owner;
    public $plan;
    public $cardToken;
    
    public function __construct($secret_key)
    {
        \Stripe\Stripe::setApiKey($secret_key);
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
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    /**
     * Create a Stripe customer for the given Stripe model.
     *
     * @param  string  $token
     * @param  array  $options
     * @return \Stripe\Customer
     */
    public function createAsStripeCustomer($token)
    {
        $options = ['email' => $this->subscriptionParam->ownerEmail];

        // Here we will create the customer instance on Stripe and store the ID of the
        // user from Stripe. This ID will correspond with the Stripe user instances
        // and allow us to retrieve users from Stripe later when we need to work.
        $this->owner = StripeCustomer::create(
            $options, $this->secretKey
        );

        // Next we will add the credit card to the user's account on Stripe using this
        // token that was provided to this method. This will allow us to bill users
        // when they subscribe to plans or we need to do one-off charges on them.
        if (! is_null($token)) {
            $this->updateCard($token);
        }
    }
    
    /**
     * Get the Stripe customer for the Stripe model.
     *
     * @return \Stripe\Customer
     */
    public function asStripeCustomer($ownerId)
    {
        $this->owner = StripeCustomer::retrieve($ownerId, $this->secretKey);
    }
    
    /**
     * Update customer's credit card.
     *
     * @param  string  $token
     * @return void
     */
    public function updateCard($token)
    {
        $token = StripeToken::retrieve($token, ['api_key' => $this->secretKey]);

        // If the given token already has the card as their default source, we can just
        // bail out of the method now. We don't need to keep adding the same card to
        // a model's account every time we go through this particular method call.
        if ($token[$token->type]->id === $this->owner->default_source) {
            return;
        }

        $card = $this->owner->sources->create(['source' => $token]);

        $this->owner->default_source = $card->id;

        $this->owner->save();

        // Next we will get the default source for this model so we can update the last
        // four digits and the card brand on the record in the database. This allows
        // us to display the information on the front-end when updating the cards.
        $source = $this->owner->default_source
                    ? $this->owner->sources->retrieve($this->owner->default_source)
                    : null;

        $this->fillCardDetails($source);
    }
    
    /**
     * Fills the model's properties with the source from Stripe.
     *
     * @param  \Stripe\Card|\Stripe\BankAccount|null  $card
     * @return $this
     */
    protected function fillCardDetails($card)
    {
        if ($card instanceof StripeCard) {
            $this->subscriptionParam->cardBrand = $card->brand;
            $this->subscriptionParam->cardLastFour = $card->last4;
        } elseif ($card instanceof StripeBankAccount) {
            $this->subscriptionParam->cardBrand = 'Bank Account';
            $this->subscriptionParam->cardLastFour = $card->last4;
        }
    }
    
    
    
    
    
    /**
     * Create a new subscriptionParam.
     *
     * @param  mixed    $price
     * @param  string    $currency
     * @return integer
     */
    public function convertPrice($price, $currency)
    {
        $currencyRates = [
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
        $rate = isset($currencyRates[$currency]) ? $currencyRates[$currency] : 100;

        return $price * $rate;
    }
    
    
    
}