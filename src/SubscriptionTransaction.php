<?php

namespace Acelle\Cashier;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class SubscriptionTransaction extends Model
{
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_PENDING = 'pending';

    const TYPE_SUBSCRIBE = 'subscribe';
    const TYPE_RENEW = 'renew';
    const TYPE_PLAN_CHANGE = 'plan_change';
    const TYPE_AUTO_CHARGE = 'plan_change';

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
        'ends_at', 'current_period_ends_at',
        'created_at', 'updated_at',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'ends_at', 'current_period_ends_at', 'status', 'description', 'amount', 'title'
    ];

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
     * Associations.
     *
     * @var object | collect
     */
    public function subscription()
    {
        // @todo dependency injection
        return $this->belongsTo('\Acelle\Cashier\Subscription');
    }

    /**
     * Change status to success.
     *
     * @var void
     */
    public function setSuccess()
    {
        $this->status = SubscriptionTransaction::STATUS_SUCCESS;
        $this->save();
    }

    /**
     * Change status to failed.
     *
     * @var void
     */
    public function setFailed()
    {
        $this->status = SubscriptionTransaction::STATUS_FAILED;
        $this->save();
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
     * Check if transaction is pending.
     *
     * @var boolean
     */
    public function isPending()
    {
        return $this->status == subscriptionTransaction::STATUS_PENDING;
    }

    /**
     * Check if transaction is failed.
     *
     * @var boolean
     */
    public function isFailed()
    {
        return $this->status == subscriptionTransaction::STATUS_FAILED;
    }
}
