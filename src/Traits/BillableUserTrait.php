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
            ->where(function($query){
                $query->whereNull('ends_at')
                      ->orWhere('ends_at', '>=', \Carbon\Carbon::now());
            })
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
        $subscription->status = Subscription::STATUS_NEW;

        // Always set ends at if gateway dose not support recurring
        if (!$gateway->isSupportRecurring()) {
            $interval = $plan->getBillableInterval();
            $intervalCount = $plan->getBillableIntervalCount();

            switch ($interval) {
                case 'month':
                    $endsAt = \Carbon\Carbon::now()->addMonth($intervalCount)->timestamp;
                    break;
                case 'day':
                    $endsAt = \Carbon\Carbon::now()->addDay($intervalCount)->timestamp;
                case 'week':
                    $endsAt = \Carbon\Carbon::now()->addWeek($intervalCount)->timestamp;
                    break;
                case 'year':
                    $endsAt = \Carbon\Carbon::now()->addYear($intervalCount)->timestamp;
                    break;
                default:
                    $endsAt = null;
            }

            $subscription->ends_at = $endsAt;
        }

        $subscription->save();

        $gateway->createSubscription($subscription);

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
     * user want to change plan.
     *
     * @return bollean
     */
    public function changePlan($plan, $gateway)
    {
        $subscription = $gateway->changePlan($this, $plan);

        $subscription->sync($gateway);
    }

    /**
     * user want to change plan.
     *
     * @return bollean
     */
    public function calcChangePlan($plan)
    {
        $currentSubscription = $this->subscription();
        $remainDays = $currentSubscription->ends_at->diffInDays(\Carbon\Carbon::now());

        // amout per day of current plan
        $currentAmount = $currentSubscription->plan->price;
        $periodDays = $currentSubscription->ends_at->diffInDays($currentSubscription->periodStartAt());
        $remainDays = $currentSubscription->ends_at->diffInDays(\Carbon\Carbon::now());
        $currentPerDayAmount = ($currentAmount/$periodDays);
        $newAmount = ($plan->price/$periodDays)*$remainDays;
        $remainAmount = $currentPerDayAmount*$remainDays;

        $amount = $newAmount - $remainAmount;

        return [
            'amount' => $amount,
        ];
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