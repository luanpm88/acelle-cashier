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
        $subscription->save();
        
        // Gateway add subscription
        $gateway->createSubscription($this, $plan, $subscription);        
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
}