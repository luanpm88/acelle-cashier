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
            'merchantId' => (isset($merchantId) ? $merchantId : 'noname'),
            'publicKey' => (isset($publicKey) ? $publicKey : 'noname'),
            'privateKey' => (isset($privateKey) ? $privateKey : 'noname'),
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
        
        // dose not support recurring, update ends at column
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
        $subscription->current_period_ends_at = $endsAt;
        
        
        // save
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
        return false;
    }
    
    /**
     * Retrieve subscription param.
     *
     * @param  Subscription  $subscription
     * @return SubscriptionParam
     */
    public function sync($subscription)
    {
        $transaction = $this->getTransaction($subscription);
        if ($subscription->isNew()) {
            // check if has transaction
            if ($transaction != null) {
                $subscription->setPending();
            }
        }
        
        if ($subscription->isPending()) {
            // check if has transaction
            if ($transaction != null) {
                $braintreeTransaction = $this->getBraintreeTransaction($transaction['id']);
                if ($braintreeTransaction->status == 'authorized') {
                    $subscription->setActive();
                    
                    // current_period_ends_at is always ends_at
                    $subscription->current_period_ends_at = $subscription->ends_at;
                }
            }
        }
                
    }
    
    /**
     * Check for notice.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function hasPending($subscription)
    {
        return false;
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
        $user = $subscription->user;
        $braintreeUser = $this->getBraintreeCustomer($user);
        $card = $this->getCardInformation($user);
        
        //var_dump($card);
        //die();
        
        $result = $this->serviceGateway->transaction()->sale([
            'amount' => $subscription->plan->getBillableAmount(),
            'paymentMethodToken' => $card->token,
        ]);
          
        if ($result->success) {
            // See $result->transaction for details
            $this->addTransaction($subscription, [
                'id' => $result->transaction->id,
            ]);
            
            $this->sync($subscription);
        } else {
            foreach($result->errors->deepAll() AS $error) {
                throw new \Exception($error->code . ": " . $error->message . "\n");
            }
        }
    }
    
    /**
     * Add transaction.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function getTransactions($subscription)
    {
        $metadata = $subscription->getMetadata();
        $transactions = isset($metadata['transactions']) ? $metadata['transactions'] : [];
        
        return $transactions;
    }
    
    /**
     * Get remote transaction.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function getBraintreeTransaction($tid)
    {
        return $this->serviceGateway->transaction()->find($tid);
    }
    
    /**
     * Add transaction.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function addTransaction($subscription, $transaction)
    {
        $transactions = $this->getTransactions($subscription);
        
        $transactions[] = $transaction;
        $subscription->updateMetadata(['transactions' => $transactions]);
    }
    
    
    /**
     * Add transaction.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function getTransaction($subscription)
    {
        $transactions = $this->getTransactions($subscription);
        
        if (empty($transactions)) {
            return null;
        } else {
            return $transactions[count($transactions)-1];
        }
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
    
    /**
     * Get subscription invoices.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function getInvoices($subscription)
    {
        $transactions = [];
        
        $transaction = $this->getBraintreeTransaction($this->getTransaction($subscription)['id']);
        
        $invoices = [];
        
        $invoices[] = new InvoiceParam([
            'createdAt' => $transaction->createdAt->getTimestamp(),
            'periodEndsAt' => $subscription->ends_at->timestamp,
            'amount' => $subscription->plan->getBillableAmount(),
            'description' => $subscription->plan->getBillableName(),
            'status' => 'active'
        ]);
        
        return $invoices;
    }
    
    /**
     * Resume subscription.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function cancelNow($subscription)
    {
        $subscription->ends_at = \Carbon\Carbon::now();
        $subscription->status = Subscription::STATUS_ENDED;
        $subscription->save();
        
        $this->sync($subscription);
    }
}