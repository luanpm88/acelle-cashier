<?php

namespace Acelle\Cashier;

use Carbon\Carbon;
use LogicException;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

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
        'created_at', 'updated_at',
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
        if (!$this->metadata) {
            return json_decode('{}', true);
        }

        return json_decode($this->metadata, true);
    }

    /**
     * Get metadata.
     *
     * @var object | collect
     */
    public function updateMetadata($data)
    {
        $metadata = (object) array_merge((array) $this->getMetadata(), $data);
        $this->metadata = json_encode($metadata);

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
     * Determine if the subscription is active, on trial, or within its grace period.
     *
     * @return bool
     */
    public function valid()
    {
        return $this->active() || $this->onTrial() || $this->onGracePeriod();
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool
     */
    public function active()
    {
        return (is_null($this->ends_at) || $this->onGracePeriod()) && !$this->isPending() && !$this->isNew();
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
        $this->save();
    }

    /**
     * Determine if the subscription has ended and the grace period has expired.
     *
     * @return bool
     */
    public function ended()
    {
        return $this->cancelled() && ! $this->onGracePeriod();
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
     * Increment the quantity of the subscription.
     *
     * @param  int  $count
     * @return $this
     */
    public function incrementQuantity($count = 1)
    {
        $this->updateQuantity($this->quantity + $count);

        return $this;
    }

    /**
     *  Increment the quantity of the subscription, and invoice immediately.
     *
     * @param  int  $count
     * @return $this
     */
    public function incrementAndInvoice($count = 1)
    {
        $this->incrementQuantity($count);

        $this->user->invoice();

        return $this;
    }

    /**
     * Decrement the quantity of the subscription.
     *
     * @param  int  $count
     * @return $this
     */
    public function decrementQuantity($count = 1)
    {
        $this->updateQuantity(max(1, $this->quantity - $count));

        return $this;
    }

    /**
     * Update the quantity of the subscription.
     *
     * @param  int  $quantity
     * @param  \Stripe\Customer|null  $customer
     * @return $this
     */
    public function updateQuantity($quantity, $customer = null)
    {
        $subscription = $this->asStripeSubscription();

        $subscription->quantity = $quantity;

        $subscription->prorate = $this->prorate;

        $subscription->save();

        $this->quantity = $quantity;

        $this->save();

        return $this;
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
     * Sync the tax percentage of the user to the subscription.
     *
     * @return void
     */
    public function syncTaxPercentage()
    {
        $subscription = $this->asStripeSubscription();

        $subscription->tax_percent = $this->user->taxPercentage();

        $subscription->save();
    }

    /**
     * Get the subscription as a Stripe subscription object.
     *
     * @return \Stripe\Subscription
     *
     * @throws \LogicException
     */
    public function asStripeSubscription()
    {
        $subscriptions = $this->user->asStripeCustomer()->subscriptions;

        if (! $subscriptions) {
            throw new LogicException('The Stripe customer does not have any subscriptions.');
        }

        return $subscriptions->retrieve($this->stripe_id);
    }

    /**
     * Check if subscription is going to expire.
     *
     * @param  Subscription    $subscription
     * @return Boolean
     */
    public function goingToExpire($days)
    {
        if (!$this->ends_at) {
            return false;
        }
        return $this->ends_at->subDay($days)->lessThanOrEqualTo(\Carbon\Carbon::now());
    }

    /**
     * Next one period to subscription.
     *
     * @param  Gateway    $gateway
     * @return Boolean
     */
    public function nextPeriod()
    {
        $endsAt = $this->ends_at;
        $interval = $this->plan->getBillableInterval();
        $intervalCount = $this->plan->getBillableIntervalCount();

        switch ($interval) {
            case 'month':
                $endsAt = $this->ends_at->addMonth($intervalCount);
                break;
            case 'day':
                $endsAt = $this->ends_at->addDay($intervalCount);
            case 'week':
                $endsAt = $this->ends_at->addWeek($intervalCount);
                break;
            case 'year':
                $endsAt = $this->ends_at->addYear($intervalCount);
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
        $startAt = $this->ends_at;
        $interval = $this->plan->getBillableInterval();
        $intervalCount = $this->plan->getBillableIntervalCount();

        switch ($interval) {
            case 'month':
                $startAt = $this->ends_at->subMonth($intervalCount);
                break;
            case 'day':
                $startAt = $this->ends_at->subDay($intervalCount);
            case 'week':
                $startAt = $this->ends_at->subWeek($intervalCount);
                break;
            case 'year':
                $startAt = $this->ends_at->subYear($intervalCount);
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
     * Check subscription status.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public static function check($gateway)
    {
        $subscriptions = self::whereNull('ends_at')->orWhere('ends_at', '>=', \Carbon\Carbon::now())->get();
        foreach ($subscriptions as $subscription) {
            $gateway->sync($subscription);
        }
    }
}
