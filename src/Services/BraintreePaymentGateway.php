<?php

namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\Cashier;
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
                if ($transaction['status'] == 'settled') {
                    // set subscription to active
                    $subscription->setActive();                    
                    // current_period_ends_at is always ends_at
                    $subscription->current_period_ends_at = $subscription->ends_at;
                }
            }
        }
        
        if ($subscription->isActive()) {
            // check if has transaction
            if ($transaction != null) {                
                // change plan transaction
                if (isset($transaction['tag']) && $transaction['tag'] == 'change_plan') {
                    if ($transaction['status'] == 'settled') {
                        $subscription->plan_id = $transaction['plan_id'];
                    }
                }
                
                // renew plan
                if (isset($transaction['tag']) && $transaction['tag'] == 'renew') {
                    if ($transaction['status'] == 'settled') {
                        $endsAt = Carbon::createFromTimestamp($transaction['endsAt']);
                        
                        $subscription->ends_at = $endsAt;
                        $subscription->current_period_ends_at = $endsAt;
                    }
                }
            }
        }
        
        $subscription->save();
    }
    
    /**
     * Check for notice.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function hasPending($subscription)
    {
        $transaction = $this->getTransaction($subscription);
        
        // check remote transaction has pending
        if ($transaction) {
            return $transaction['status'] != 'settled';
        }
        
        return false;
    }
    
    /**
     * Get notice message.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function getPendingNotice($subscription)
    {
        $transaction = $this->getTransaction($subscription);
        
        return trans('cashier::messages.braintree.has_transaction_pending', [
            'description' => $transaction['description'],
            'amount' => $transaction['amount'],
            'url' => action('\Acelle\Cashier\Controllers\BraintreeController@pending', [
                'subscription_id' => $subscription->uid,
            ]),
        ]);
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
                'createdAt' => Carbon::now()->timestamp,
                'periodEndsAt' => $subscription->ends_at->timestamp,
                'amount' => $subscription->plan->getBillableFormattedPrice(),
                'description' => trans('cashier::messages.braintree.subscribed_to_plan', ['plan' => $subscription->plan->getBillableName()]),
            ]);
            
            $this->sync($subscription);
        } else {
            foreach($result->errors->deepAll() AS $error) {
                throw new \Exception($error->code . ": " . $error->message . "\n");
            }
        }
    }
    
    /**
     * Get transactions.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function getTransactions($subscription, $remote = false)
    {
        $metadata = $subscription->getMetadata();
        $transactions = isset($metadata['transactions']) ? $metadata['transactions'] : [];
        
        
        foreach($transactions as $key => $transaction) {
            // overide admin status
            if (isset($transaction['adminStatus'])) {
                $transactions[$key]['status'] = $transaction['adminStatus'];
            
            // get remote status
            } else if ($remote) {
                // get remote transaction
                $braintreeTransaction = $this->getBraintreeTransaction($transaction['id']);
                if ($braintreeTransaction) {
                    $transactions[$key]['status'] = $braintreeTransaction->status;
                }
            }
        }
        
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
        return isset($tid) ? $this->serviceGateway->transaction()->find($tid) : null;
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
        }
        
        $transaction = $transactions[count($transactions)-1];
        
        // get admin status
        if (isset($transaction['adminStatus'])) {
            $transaction['status'] = $transaction['adminStatus'];
        } else {
            // get remote transaction
            $braintreeTransaction = $this->getBraintreeTransaction($transaction['id']);
            if ($braintreeTransaction) {
                $transaction['status'] = $braintreeTransaction->status;
            }
        }
        

        
        return $transaction;
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
        $invoices = [];
        
        $transactions = array_reverse($this->getTransactions($subscription, true));            
        
        foreach ($transactions as $transaction) {
            $invoices[] = new InvoiceParam([
                'createdAt' => $transaction['createdAt'],
                'periodEndsAt' => $transaction['periodEndsAt'],
                'amount' => $transaction['amount'],
                'description' => $transaction['description'],
                'status' => $transaction['status'],
            ]);
        }
        
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
    
    /**
     * Get renew url.
     *
     * @return string
     */
    public function getChangePlanUrl($subscription, $plan_id, $returnUrl='/')
    {
        return action("\Acelle\Cashier\Controllers\\BraintreeController@changePlan", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
            'plan_id' => $plan_id,
        ]);
    }
    
    /**
     * Swap subscription plan.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function changePlan($subscription, $newPlan)
    {
        // calc result
        $result = Cashier::calcChangePlan($subscription, $newPlan);
        $amount = round($result["amount"], 2);
        $endsAt = $result["endsAt"];
        
        $newPlan->price = $amount;
        
        // charging
        $user = $subscription->user;
        $braintreeUser = $this->getBraintreeCustomer($user);
        $card = $this->getCardInformation($user);
        
        // amount == 0
        if ($amount == 0) {
            // add force local transaction
            $this->addTransaction($subscription, [
                'id' => null,
                'createdAt' => Carbon::now()->timestamp,
                'periodEndsAt' => $endsAt->timestamp,
                'amount' => $newPlan->getBillableFormattedPrice(),
                'description' => trans('cashier::messages.braintree.change_plan', ['plan' => $newPlan->getBillableName()]),
                'tag' => 'change_plan',
                'plan_id' => $newPlan->getBillableId(),
                'status' => 'settled',
            ]);
            
            // sync transaction
            $this->sync($subscription);
            
            return $subscription;
        }
        
        // Braintree transaction if amount > 0
        if ($amount > 0) {
            $result = $this->serviceGateway->transaction()->sale([
                'amount' => $amount,
                'paymentMethodToken' => $card->token,
            ]);
              
            if ($result->success) {
                // add local transaction for payment
                $this->addTransaction($subscription, [
                    'id' => $result->transaction->id,
                    'createdAt' => Carbon::now()->timestamp,
                    'periodEndsAt' => $endsAt->timestamp,
                    'amount' => $newPlan->getBillableFormattedPrice(),
                    'description' => trans('cashier::messages.braintree.change_plan_to', ['plan' => $newPlan->getBillableName()]),
                    'tag' => 'change_plan',
                    'plan_id' => $newPlan->getBillableId()
                ]);
            } else {
                foreach($result->errors->deepAll() AS $error) {
                    throw new \Exception($error->code . ": " . $error->message . "\n");
                }
            }
        }
        
        // sync transaction
        $this->sync($subscription);
        
        return $subscription;
    }
    
    /**
     * Get renew url.
     *
     * @return string
     */
    public function getRenewUrl($subscription, $returnUrl='/')
    {
        return action("\Acelle\Cashier\Controllers\\BraintreeController@renew", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }
    
    /**
     * Renew subscription plan.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function renew($subscription)
    {
        // calc result
        $amount = $subscription->plan->getBillableAmount();
        $endsAt = $subscription->nextPeriod();
        
        // charging
        $plan = $subscription->plan;
        $user = $subscription->user;
        $braintreeUser = $this->getBraintreeCustomer($user);
        $card = $this->getCardInformation($user);
        
        // amount == 0
        if ($amount == 0) {
            // add force local transaction
            $this->addTransaction($subscription, [
                'id' => null,
                'createdAt' => Carbon::now()->timestamp,
                'periodEndsAt' => $endsAt->timestamp,
                'amount' => $plan->getBillableFormattedPrice(),
                'description' => trans('cashier::messages.braintree.renew_plan', ['plan' => $plan->getBillableName()]),
                'tag' => 'renew',
                'plan_id' => $plan->getBillableId(),
                'endsAt' => $endsAt->timestamp,
                'status' => 'settled',
            ]);
            
            // sync transaction
            $this->sync($subscription);
            
            return $subscription;
        }
        
        // Braintree transaction if amount > 0
        if ($amount > 0) {
            $result = $this->serviceGateway->transaction()->sale([
                'amount' => $amount,
                'paymentMethodToken' => $card->token,
            ]);
              
            if ($result->success) {
                // add local transaction for payment
                $this->addTransaction($subscription, [
                    'id' => $result->transaction->id,
                    'createdAt' => Carbon::now()->timestamp,
                    'periodEndsAt' => $endsAt->timestamp,
                    'amount' => $plan->getBillableFormattedPrice(),
                    'description' => trans('cashier::messages.braintree.renew_plan', ['plan' => $plan->getBillableName()]),
                    'tag' => 'renew',
                    'endsAt' => $endsAt->timestamp,
                    'plan_id' => $plan->getBillableId()
                ]);
            } else {
                foreach($result->errors->deepAll() AS $error) {
                    throw new \Exception($error->code . ": " . $error->message . "\n");
                }
            }
        }
        
        // sync transaction
        $this->sync($subscription);
        
        return $subscription;
    }
    
    /**
     * Allow admin approve pending subscription.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function setActive($subscription)
    {
        $transaction = $this->getTransaction($subscription);
        $transaction['adminStatus'] = 'settled';
        
        $transactions = $this->getTransactions($subscription);
        $transactions[count($transactions) - 1] = $transaction;
        
        // save
        $subscription->updateMetadata(['transactions' => $transactions]);
        
        $subscription->sync($subscription);
    }
}