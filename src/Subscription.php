<?php

namespace Acelle\Cashier;

use Carbon\Carbon;
use LogicException;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Acelle\Cashier\SubscriptionTransaction;

class Subscription extends Model
{
    const STATUS_NEW = 'new';
    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_ENDED = 'ended';

    /**
     * The attributes that are not mass assignable.
     *
     * @var array
     */
    protected $guarded = [];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'trial_ends_at', 'ends_at', 'current_period_ends_at',
        'created_at', 'updated_at', 'started_at', 'last_period_ends_at'
    ];

    /**
     * Indicates if the plan change should be prorated.
     *
     * @var bool
     */
    protected $prorate = true;

    /**
     * The date on which the billing cycle should be anchored.
     *
     * @var string|null
     */
    protected $billingCycleAnchor = null;

    /**
     * Bootstrap any application services.
     */
    public static function boot()
    {
        parent::boot();

        // Create uid when creating list.
        static::creating(function ($item) {
            // Create new uid
            $uid = uniqid();
            while (self::where('uid', '=', $uid)->count() > 0) {
                $uid = uniqid();
            }
            $item->uid = $uid;
        });
    }

    /**
     * Find item by uid.
     *
     * @return object
     */
    public static function findByUid($uid)
    {
        return self::where('uid', '=', $uid)->first();
    }

    /**
     * Get metadata.
     *
     * @var object | collect
     */
    public function getMetadata()
    {
        if (!$this['metadata']) {
            return json_decode('{}', true);
        }

        return json_decode($this['metadata'], true);
    }

    /**
     * Get metadata.
     *
     * @var object | collect
     */
    public function updateMetadata($data)
    {
        $metadata = (object) array_merge((array) $this->getMetadata(), $data);
        $this['metadata'] = json_encode($metadata);

        $this->save();
    }

    /**
     * Associations.
     *
     * @var object | collect
     */
    public function plan()
    {
        // @todo dependency injection
        return $this->belongsTo('\Acelle\Model\Plan', 'plan_id', 'uid');
    }

    /**
     * Get the user that owns the subscription.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        // @todo dependency injection
        return $this->belongsTo('\Acelle\Model\Customer', 'user_id', 'uid');
    }

    /**
     * Associations.
     *
     * @var object | collect
     */
    public function subscriptionTransactions()
    {
        // @todo dependency injection
        return $this->hasMany('\Acelle\Cashier\SubscriptionTransaction');
    }

    /**
     * Associations.
     *
     * @var object | collect
     */
    public function subscriptionLogs()
    {
        // @todo dependency injection
        return $this->hasMany('\Acelle\Cashier\SubscriptionLog');
    }

    /**
     * Determine if the subscription is recurring and not on trial.
     *
     * @return bool
     */
    public function isRecurring()
    {
        return ! $this->onTrial() && ! $this->cancelled();
    }

    /**
     * Get the model related to the subscription.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function owner()
    {
        $class = Cashier::stripeModel();

        return $this->belongsTo($class, (new $class)->getForeignKey());
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function isActive()
    {
        return $this->status == self::STATUS_ACTIVE;
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function isNew()
    {
        return $this->status == self::STATUS_NEW;
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function isPending()
    {
        return $this->status == self::STATUS_PENDING;
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function isDone()
    {
        return $this->status == self::STATUS_DONE;
    }

    /**
     * Determine if the subscription is recurring and not on trial.
     *
     * @return bool
     */
    public function recurring()
    {
        return ! $this->onTrial() && ! $this->cancelled();
    }



    /**
     * Determine if the subscription is no longer active.
     *
     * @return bool
     */
    public function cancelled()
    {
        return ! is_null($this->ends_at);
    }

    /**
     * Determine if the subscription is ended.
     *
     * @return bool
     */
    public function isEnded()
    {
        return $this->status == self::STATUS_ENDED;
    }

    /**
     * Determine if the subscription is ended.
     *
     * @return bool
     */
    public function setEnded()
    {
        $this->status = self::STATUS_ENDED;
        $this->ends_at = \Carbon\Carbon::now();
        $this->error = null;
        $this->save();
    }

    /**
     * Determine if the subscription is pending.
     *
     * @return bool
     */
    public function setPending()
    {
        $this->status = self::STATUS_PENDING;
        $this->save();
    }

    /**
     * Determine if the subscription is pending.
     *
     * @return bool
     */
    public function setActive()
    {
        $this->status = self::STATUS_ACTIVE;
        $this->started_at = \Carbon\Carbon::now();
        $this->save();
    }

    /**
     * Determine if the subscription is within its trial period.
     *
     * @return bool
     */
    public function onTrial()
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Determine if the subscription is within its grace period after cancellation.
     *
     * @return bool
     */
    public function onGracePeriod()
    {
        return $this->ends_at && $this->ends_at->isFuture();
    }

    /**
     * Indicate that the plan change should not be prorated.
     *
     * @return $this
     */
    public function noProrate()
    {
        $this->prorate = false;

        return $this;
    }

    /**
     * Change the billing cycle anchor on a plan change.
     *
     * @param  \DateTimeInterface|int|string  $date
     * @return $this
     */
    public function anchorBillingCycleOn($date = 'now')
    {
        if ($date instanceof DateTimeInterface) {
            $date = $date->getTimestamp();
        }

        $this->billingCycleAnchor = $date;

        return $this;
    }

    /**
     * Force the trial to end immediately.
     *
     * This method must be combined with swap, resume, etc.
     *
     * @return $this
     */
    public function skipTrial()
    {
        $this->trial_ends_at = null;

        return $this;
    }

    /**
     * Mark the subscription as cancelled.
     *
     * @return void
     */
    public function markAsCancelled()
    {
        $this->fill(['ends_at' => Carbon::now()->startOfDay()])->save();
    }

    /**
     * Next one period to subscription.
     *
     * @param  Gateway    $gateway
     * @return Boolean
     */
    public function nextPeriod()
    {
        $endsAt = $this->current_period_ends_at;
        $interval = $this->plan->getBillableInterval();
        $intervalCount = $this->plan->getBillableIntervalCount();

        switch ($interval) {
            case 'month':
                $endsAt = $endsAt->addMonthsNoOverflow($intervalCount);
                break;
            case 'day':
                $endsAt = $endsAt->addDay($intervalCount);
            case 'week':
                $endsAt = $endsAt->addWeek($intervalCount);
                break;
            case 'year':
                $endsAt = $endsAt->addYearsNoOverflow($intervalCount);
                break;
            default:
                $endsAt = null;
        }

        return $endsAt;
    }

    /**
     * Next one period to subscription.
     *
     * @param  Gateway    $gateway
     * @return Boolean
     */
    public function periodStartAt()
    {
        $startAt = $this->current_period_ends_at;
        $interval = $this->plan->getBillableInterval();
        $intervalCount = $this->plan->getBillableIntervalCount();

        switch ($interval) {
            case 'month':
                $startAt = $startAt->subMonthsNoOverflow($intervalCount);
                break;
            case 'day':
                $startAt = $startAt->subDay($intervalCount);
            case 'week':
                $startAt = $startAt->subWeek($intervalCount);
                break;
            case 'year':
                $startAt = $startAt->subYearsNoOverflow($intervalCount);
                break;
            default:
                $startAt = null;
        }

        return $startAt;
    }

    /**
     * Add one period to subscription.
     *
     * @param  Gateway    $gateway
     * @return Boolean
     */
    public function addPeriod()
    {
        $this->ends_at = $this->nextPeriod();
        $this->save();
    }

    /**
     * Check if payment is claimed.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function isPaymentClaimed()
    {
        return $this->payment_claimed;
    }

    /**
     * Claim payment.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function claimPayment()
    {
        $this->payment_claimed = true;
        $this->save();
    }

    /**
     * Get gateway.
     *
     * @return gateway
     */
    public function getPaymentGateway()
    {
        return \Acelle\Cashier\Cashier::getPaymentGateway($this->gateway);
    }

    /**
     * Check subscription status.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public static function checkAll()
    {
        $subscriptions = self::whereNull('ends_at')->orWhere('ends_at', '>=', \Carbon\Carbon::now())->get();
        foreach ($subscriptions as $subscription) {
            // get sub gateway
            $subGateway = $subscription->getPaymentGateway();

            // normal check
            $subGateway->check($subscription);

            // check plans
            if (method_exists($subGateway, 'checkAll')) {
                $subGateway->checkAll();
            }
        }
    }

    /**
     * Check if subscription is expired.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function isExpired()
    {
        return isset($this->ends_at) && \Carbon\Carbon::now()->endOfDay() > $this->ends_at;
    }

    /**
     * Get period by start date.
     *
     * @param  date  $date
     * @return date
     */
    public function getPeriodEndsAt($startDate)
    {        
        // dose not support recurring, update ends at column
        $interval = $this->plan->getBillableInterval();
        $intervalCount = $this->plan->getBillableIntervalCount();

        switch ($interval) {
            case 'month':
                $endsAt = $startDate->addMonthsNoOverflow($intervalCount);
                break;
            case 'day':
                $endsAt = $startDate->addDay($intervalCount);
            case 'week':
                $endsAt = $startDate->addWeek($intervalCount);
                break;
            case 'year':
                $endsAt = $startDate->addYearsNoOverflow($intervalCount);
                break;
            default:
                $endsAt = null;
        }

        return $endsAt;
    }
    
    /**
     * Start subscription.
     *
     * @param  date  $date
     * @return date
     */
    public function start()
    {
        $this->ends_at = null;
        $this->current_period_ends_at = $this->getPeriodEndsAt(\Carbon\Carbon::now());
        $this->status = self::STATUS_ACTIVE;
        $this->started_at = \Carbon\Carbon::now();
        $this->save();
    }

    /**
     * Subscription transactions.
     *
     * @return array
     */
    public function getTransactions()
    {
        return $this->subscriptionTransactions()->orderBy('created_at', 'desc')->get();
    }

    /**
     * Subscription transactions.
     *
     * @return array
     */
    public function getLogs()
    {
        return $this->subscriptionLogs()->orderBy('created_at', 'desc')->get();
    }

    /**
     * Subscription transactions.
     *
     * @return array
     */
    public function addTransaction($type, $data)
    {
        $transaction = new SubscriptionTransaction();
        $transaction->subscription_id = $this->id;
        $transaction->type = $type;
        $transaction->fill($data);

        if (isset($data['metadata'])) {
            $transaction['metadata'] = json_encode($data['metadata']);
        }

        $transaction->save();

        return $transaction;
    }

    /**
     * Subscription transactions.
     *
     * @return array
     */
    public function addLog($type, $data, $transaction_id=null)
    {
        $log = new SubscriptionLog();
        $log->subscription_id = $this->id;
        $log->type = $type;
        $log->transaction_id = $transaction_id;
        $log->save();

        if (isset($data)) {
            $log->updateData($data);
        }

        return $log;
    }

    /**
     * Cancel subscription. Set ends at to the end of period.
     *
     * @return void
     */
    public function cancel()
    {
        $this->ends_at = $this->current_period_ends_at;
        $this->save();
    }

    /**
     * Cancel subscription. Set ends at to the end of period.
     *
     * @return void
     */
    public function resume()
    {
        $this->ends_at = null;
        $this->save();
    }

    /**
     * Cancel subscription. Set ends at to the end of period.
     *
     * @return void
     */
    public function cancelNow()
    {        
        $this->setEnded();
    }

    public function changePlan($newPlan, $amount=null) {
        // calc when change plan
        $result = Cashier::calcChangePlan($this, $newPlan);

        // set new amount to plan
        $newPlan->price = $result['amount'];
        if ($amount) {
            $newPlan->price = $amount;
        }

        // update subscription date
        if ($this->current_period_ends_at != $result['endsAt']) {
            // save last period
            $this->last_period_ends_at = $this->current_period_ends_at;
            // set new current period
            $this->current_period_ends_at = $result['endsAt'];
        }

        if (isset($this->ends_at) && $this->ends_at < $result['endsAt']) {
            $this->ends_at = $result['endsAt'];
        }
        $this->plan_id = $newPlan->getBillableId();
        $this->save();
    }

    public function renew() {
        $this->ends_at = $this->nextPeriod();
        
        // save last period
        $this->last_period_ends_at = $this->current_period_ends_at;
        // set new current period
        $this->current_period_ends_at = $this->nextPeriod();
        
        $this->save();
    }

    public function isExpiring() {      
        // check if recurring accur
        if (\Carbon\Carbon::now()->diffInDays($this->current_period_ends_at) < config('cashier.recurring_charge_before_days')) {
            return true;
        }

        return false;
    }

    /**
     * Check if has error
     *
     * @return void
     */
    public function hasError()
    {
        return $this->error;
    }

    /**
     * Get error
     *
     * @return void
     */
    public function getError()
    {
        if ($this->hasError()) {
            return json_decode($this->error, true);
        }
    }

    /**
     * Check if is renew pending
     *
     * @return void
     */
    public function hasRenewPending()
    {
        return ($this->hasError() &&  in_array($this->getError()["type"], ['renew']));
    }

    /**
     * Check if is renew pending
     *
     * @return void
     */
    public function hasChangePlanPending()
    {
        return ($this->hasError() &&  in_array($this->getError()["type"], ['change_plan']));
    }

    /**
     * Check if can renew free plan
     *
     * @return void
     */
    public function canRenewPlan()
    {
        return (
            config('cashier.renew_free_plan') == 'yes' ||
            (config('cashier.renew_free_plan') == 'no' && $this->plan->getBillableAmount() > 0)
        );
    }
}
