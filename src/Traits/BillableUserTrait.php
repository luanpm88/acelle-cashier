<?php

namespace Acelle\Cashier\Traits;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\SubscriptionBuilder;
use Acelle\Cashier\Subscription;

trait BillableUserTrait
{
    /**
     * Associations.
     *
     * @var object | collect
     */
    public function subscriptions()
    {
        // @todo how to know customer has uid
        return $this->hasMany('Acelle\Cashier\Subscription', 'user_id', 'uid')
            ->whereNull('ends_at')->orWhere('ends_at', '>=', \Carbon\Carbon::now())
            ->orderBy('created_at', 'desc');
    }
    
    public function subscription()
    {
        return $this->subscriptions()->first();
    }
    
    public function subscriptionValid()
    {
        $subscription = $this->subscription();
        return is_object($subscription) && $subscription->valid();
    }
    
    /**
     * Begin creating a new subscription.
     *
     * @param  string  $subscription
     * @param  string  $plan
     * @return \Laravel\Cashier\SubscriptionBuilder
     */
    public function createSubscription($plan, $gateway)
    {
        // update subscription model
        $subscription = new Subscription();
        $subscription->user_id = $this->getBillableId();
        $subscription->plan_id = $plan->getBillableId();
        
        if (!$gateway->isSupportRecurring()) {
            $subscription->ends_at = \Carbon\Carbon::now()->addMonth(1)->timestamp;
        }
        
        $subscription->save();
        
        return $subscription;
    }
    
    /**
     * Check if user has card and payable.
     *
     * @return bollean
     */
    public function billableUserHasCard($gateway)
    {
        return $gateway->billableUserHasCard($this);
    }
    
    /**
     * update user card information.
     *
     * @return bollean
     */
    public function billableUserUpdateCard($gateway, $params)
    {
        return $gateway->billableUserUpdateCard($this, $params);
    }
    
    /**
     * Resume subscription now.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function resumeSubscription($gateway)
    {
        $subscription = $this->subscription();
        $subscription->resume($gateway);
    }
    
    /**
     * Cancel subscription at the end of current period.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function cancelSubscription($gateway)
    {
        $subscription = $this->subscription();
        $subscription->cancel($gateway);
    }
    
    /**
     * Cancel subscription now.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function cancelNowSubscription($gateway)
    {
        $subscription = $this->subscription();
        $subscription->cancelNow($gateway);
    }
    
    /**
     * user want to change plan.
     *
     * @return bollean
     */
    public function changePlan($plan, $gateway)
    {
        // get current subscription
        $subscription = $this->subscription();
        
        if ($gateway->isSupportRecurring()) {
            $subscription->swap($plan, $gateway);
        } else {
            $subscription->markAsCancelled();            
            $this->createSubscription($plan, $gateway);
        }
    }
    
    /**
     * Retrive subscription from remote.
     *
     * @return $this
     */
    public function retrieveSubscription($gateway)
    {
        // get current subscription
        $subscription = $this->subscription();
        
        return $subscription->retrieve($gateway);
    }

}