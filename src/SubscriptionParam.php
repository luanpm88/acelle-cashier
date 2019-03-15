<?php

namespace Acelle\Cashier;

use Carbon\Carbon;

class SubscriptionParam
{
    // Owner attributes
    public $ownerId;
    public $ownerEmail;
    // Plan attributes
    public $planId;
    public $planName;
    public $interval;
    public $intervalCount;
    public $amount;
    public $currency;
    public $cardBrand;
    public $cardLastFour;
    // Subscription attributes
    public $currentPeriodEnd;
    public $endsAt;
    public $isPending;
    
    
    /**
     * Create a new Subscription param item instance.
     *
     * @param  Array  $options
     * @return void
     */
    public function __construct($options = [])
    {
        $has = get_object_vars($this);
        foreach ($has as $name => $oldValue) {
            $this->$name = isset($options[$name]) ? $options[$name] : NULL;
        }
    }
}