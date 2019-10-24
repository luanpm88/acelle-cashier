<?php

namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Carbon\Carbon;
use Acelle\Cashier\Subscription;
use Acelle\Cashier\InvoiceParam;

class BraintreePaymentGateway implements PaymentGatewayInterface
{
    public $serviceGateway;
    
    public function __construct($environment, $merchantId, $publicKey, $privateKey) {
        $this->serviceGateway = new \Braintree_Gateway([
            'environment' => $environment,
            'merchantId' => $merchantId,
            'publicKey' => $publicKey,
            'privateKey' => $privateKey,
        ]);
    }
    
    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function validate()
    {
        try {
            $clientToken = $this->serviceGateway->clientToken()->generate([
                "customerId" => '123'
            ]);
        } catch (\Braintree_Exception_Authentication $e) {
            throw new \Exception('Braintree Exception Authentication Failed');
        } catch (\Exception $e) {
            // do nothing
        }
    }
    
    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function isValid()
    {
        try {
            $this->validate();
        } catch (\Exception $e) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Create a new subscription.
     *
     * @param  mixed                $token
     * @param  Subscription         $subscription
     * @return void
     */
    public function create($customer, $plan)
    {
        // update subscription model
        $subscription = new Subscription();
        $subscription->user_id = $customer->getBillableId();
        $subscription->plan_id = $plan->getBillableId();
        $subscription->status = Subscription::STATUS_NEW;
        
        $subscription->save();
        
        return $subscription;
    }
    
    /**
     * Check if support recurring.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function isSupportRecurring()
    {
        return true;
    }
    
    /**
     * Retrieve subscription param.
     *
     * @param  Subscription  $subscription
     * @return SubscriptionParam
     */
    public function sync($subscription)
    {
        
    }
    
    /**
     * Update customer card.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function updateCard($user, $nonce)
    {
        $braintreeCustomer = $this->getBraintreeCustomer($user);
        
        // update card
        $updateResult = $this->serviceGateway->customer()->update(
            $braintreeCustomer->id,
            [
              'paymentMethodNonce' => $nonce
            ]
        );
    }
    
    /**
     * Check if subscription has future payment pending.
     *
     * @param  Subscription    $subscription
     * @return Boolean
     */
    public function getCardInformation($user)
    {
        // get or create plan
        $braintreeCustomer = $this->getBraintreeCustomer($user);

        $cards = $braintreeCustomer->paymentMethods;

        return empty($cards) ? NULL : $cards[0];
    }
    
    /**
     * Get the Stripe customer instance for the current user and token.
     *
     * @param  SubscriptionParam    $subscriptionParam
     * @return \Stripe\Customer
     */
    protected function getBraintreeCustomer($user)
    {
        // Find in gateway server
        $braintreeCustomers = $this->serviceGateway->customer()->search([
            \Braintree_CustomerSearch::email()->is($user->getBillableEmail())
        ]);
        
        if ($braintreeCustomers->maximumCount() == 0) {
            // create if not exist
            $result = $this->serviceGateway->customer()->create([
                'email' => $user->getBillableEmail(),
            ]);
            
            if ($result->success) {
                $braintreeCustomer = $result->customer;
            } else {
                foreach($result->errors->deepAll() AS $error) {
                    throw new \Exception($error->code . ": " . $error->message . "\n");
                }
            }
        } else {
            $braintreeCustomer = $braintreeCustomers->firstItem();
        }


        return $braintreeCustomer;
    }
    
    /**
     * Chareg subscription.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function charge($subscription)
    {
        // get or create plan
        $stripePlan = $this->getStripePlan($subscription->plan);

        // get or create plan
        $stripeCustomer = $this->getStripeCustomer($subscription->user);

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
        
        $this->sync($subscription);
    }
    
    /**
     * Get remote plan.
     *
     * @param  Plan  $plan
     * @return object
     */
    public function getRemotePlan($plan)
    {
        // get plan id from setting
        $mapping = json_decode(config('cashier.gateways.braintree.fields.mapping'), true);
        
        if (!isset($mapping[$plan->getBillableId()])) {
            throw new \Exception('The plan has not mapped yet!');
        }
        
        $remoteId = $mapping[$plan->getBillableId()];
        $remotePlans = $this->serviceGateway->plan()->all();
        
        foreach($remotePlans as $plan) {
            if ($plan->id == $remoteId) {
                return $plan;
            }
        }
        
        throw new \Exception('Can not find remote plan with id = ' . $remoteId); 
    }
}