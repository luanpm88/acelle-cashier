<?php

namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\SubscriptionParam;
use Carbon\Carbon;

class BraintreePaymentGateway implements PaymentGatewayInterface
{
    public $name = 'braintree';
    public $subscriptionParam;
    public $owner;
    public $plan;
    public $nonce;
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
     * Get the Braintree customer instance for the current user and token.
     *
     * @param  SubscriptionParam    $subscriptionParam
     * @return \Stripe\Customer
     */
    protected function getBraintreeCustomer($user)
    {
        // Find in gateway server
        $this->serviceGateway->customer()->search([
            \Braintree_CustomerSearch::token()->is($user->getBillableId())
        ]);
        
        
        // create if not exist
        $stripeCustomer = \Stripe\Customer::create([
            'metadata' => [
                'local_user_id' => $user->getBillableId(),
            ],
        ]);
        
        return $stripeCustomer;
    }
    
    /**
     * Check if customer has valid card.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function billableUserHasCard($user)
    {
        $stripeCustomer = $this->getStripeCustomer($user);
        
        // get card from customer
        return isset($stripeCustomer->default_source);
    }
    
    
    
    
    
    
    
    public function getClientToken($customerId = null) {
        if (isset($customerId)) {
            try {
                return $this->serviceGateway->clientToken()->generate(['customerId' => $customerId]);
            } catch (\Exception $e) {                
            }
            
        }
        
        return $this->serviceGateway->clientToken()->generate();
    }
    
    /**
     * Create a new subscriptionParam.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function createSubscription($user, $plan, $subscription)
    {
        $this->nonce = $request->nonce;
        
        // Customer
        $result = $this->serviceGateway->customer()->create([
            'firstName' => 'Mike',
            'lastName' => 'Jones',
            'company' => 'Jones Co.',
            'paymentMethodNonce' => $this->nonce
        ]);
        
        if ($result->success) {
            echo($result->customer->id);
            echo($result->customer->paymentMethods[0]->token);
        } else {
            foreach($result->errors->deepAll() AS $error) {
                echo($error->code . ": " . $error->message . "\n");
            }
        }
        
        die();
        
        $result = $this->serviceGateway->subscription()->create([
            'paymentMethodToken' => $this->nonce,
            'planId' => 'silver_plan'
        ]);
        
        var_dump($result);
    }
    
    /**
     * Retrieve subscription param.
     *
     * @param  Subscription  $subscription
     * @return SubscriptionParam
     */
    public function retrieveSubscription($remoteSubscriptionId)
    {
        
    }
}