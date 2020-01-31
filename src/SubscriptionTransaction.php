<?php

namespace Acelle\Cashier;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class SubscriptionTransaction extends Model
{
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';

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
        'ends_at', 'current_period_ends_at', 'status', 'description', 'amount'
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
}
