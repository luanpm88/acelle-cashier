<?php

namespace Acelle\Cashier\Builders;

use Carbon\Carbon;
use DateTimeInterface;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;

class SubscriptionBuilder
{
    /**
     * The model that is subscribing.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $payer;

    /**
     * The model that is plan.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $plan;
    
    /**
     * The model that is subscribing.
     *
     * @var PaymentGatewayInterface
     */
    protected $gateway;
    
    /**
     * The date and time the trial will expire.
     *
     * @var \Carbon\Carbon
     */
    protected $trialExpires;

    /**
     * Indicates that the trial should end immediately.
     *
     * @var bool
     */
    protected $skipTrial = false;
    
    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $owner
     * @param  mixed  $plan
     * @return void
     */
    public function __construct($payer, $plan, $gateway)
    {
        $this->payer = $payer;
        $this->plan = $plan;
        $this->gateway = $gateway;
    }
    
    /**
     * Create a new Stripe subscription.
     *
     * @param  string|null  $token
     * @param  array  $options
     * @return \Acelle\Cashier\Models\Subscription
     */
    public function create($token = null, array $options = [])
    {
        // Create remote subscription
        // $customer = $this->getStripeCustomer($token, $options);
        // $subscription = $customer->subscriptions->create($this->buildPayload());
        $rSubscription = $this->gateway->createSubscription($this->buildPayload());
        

        if ($this->skipTrial) {
            $trialEndsAt = null;
        } else {
            $trialEndsAt = $this->trialExpires;
        }

        return $this->payer->subscriptions()->create([
            'stripe_id' => $subscription->id,
            'stripe_plan' => $this->plan,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => null,
        ]);
    }
}