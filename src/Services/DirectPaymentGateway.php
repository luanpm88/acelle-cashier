<?php

namespace Acelle\Cashier\Services;

use Stripe\Card as StripeCard;
use Stripe\Token as StripeToken;
use Stripe\Customer as StripeCustomer;
use Stripe\Subscription as StripeSubscription;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\Subscription;
use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\InvoiceParam;
use Carbon\Carbon;

class DirectPaymentGateway implements PaymentGatewayInterface
{
    public $db;
    
    public function __construct($database_path)
    {
        $this->db = new \SQLite3(storage_path($database_path));
        
        // Create transaction table if not exist
        $sql =<<<EOF
            CREATE TABLE IF NOT EXISTS transactions
            (ID INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id           VARCHAR(255)    NOT NULL,
            price                     REAL            NOT NULL,
            currency                  VARCHAR(255)    NOT NULL,
            status                    CHAR(50)        NOT NULL,
            description           TEXT    NOT NULL,
            created_at                INTEGER         NOT NULL);
EOF;
        $act = $this->db->exec($sql);
        if(!$act){
            throw new \Exception($this->db->lastErrorMsg());
        }
    }
    
    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function validate()
    {
        if(!$this->db) {
            throw new \Exception($this->db->lastErrorMsg());
        } else {
            return true;
        }
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
     * Create a new subscriptionParam.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function charge($subscription, $options=[])
    {
        $created_at = \Carbon\Carbon::now()->timestamp;
        $status = Subscription::STATUS_PENDING;
        $description = 'Transaction was created. Waiting for payment...';
        $amount = $subscription->plan->getBillableAmount();
        $currency = $subscription->plan->getBillableCurrency();
        
        // custom amount
        if (isset($options['amount'])) {
            $amount = $options['amount'];
        }
        
        // Create new transaction for payment
        $sql =<<<EOF
            INSERT INTO transactions (subscription_id, price, currency, status, description, created_at)  
            VALUES ('{$subscription->uid}', {$amount}, '{$currency}', '{$status}', '{$description}', {$created_at});
EOF;
        $act = $this->db->exec($sql);
        if(!$act){
            throw new \Exception($this->db->lastErrorMsg());
        }
    }
    
    /**
     * Check if customer has valid card.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function billableUserHasCard($user)
    {
        return true;
    }
    
    /**
     * Update user card.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function billableUserUpdateCard($user, $params)
    {
        
    }
    
    /**
     * Retrieve subscription param.
     *
     * @param  Subscription  $subscription
     * @return SubscriptionParam
     */
    public function retrieveSubscription($subscriptionId)
    {
        $subscription = Subscription::findByUid($subscriptionId);
        
        // Check if plan is free
        if ($subscription->plan->getBillableAmount() == 0) {
            return new SubscriptionParam([
                'status' => Subscription::STATUS_DONE,
                'createdAt' => $subscription->created_at,
            ]);
        }
        
        $sql =<<<EOF
            SELECT * from transactions WHERE subscription_id='{$subscriptionId}' ORDER BY created_at DESC;
EOF;

        $ret = $this->db->query($sql);
        $row = $ret->fetchArray(SQLITE3_ASSOC);
        
        if(!$row) {
            throw new \Exception("Can not find the subscription with id = $subscriptionId");
        }
        
        $subscriptionParam = new SubscriptionParam([
            'status' => $row['status'],
            'amount' => $row['price'],
        ]);
        
        
        return $subscriptionParam;
    }
    
    /**
     * Cancel subscription.
     *
     * @param  Subscription  $subscription
     * @return [$currentPeriodEnd]
     */
    public function cancelSubscription($subscriptionId)
    {
        
    }
    
    /**
     * Resume subscription.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function resumeSubscription($subscriptionId)
    {
        
    }
    
    /**
     * Resume subscription.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function cancelNowSubscription($subscriptionId)
    {
        
    }
    
    /**
     * Swap subscription plan.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function changePlan($user, $plan)
    {
        $currentSubscription = $user->subscription();
        $currentSubscriptionParam = $currentSubscription->retrieve($this);
        $currentAmount = $currentSubscriptionParam->amount;
        $periodDays = $currentSubscriptionParam->endsAt->diffInDays($currentSubscription->created_at);
        $remainDays = $currentSubscriptionParam->endsAt->diffInDays(\Carbon\Carbon::now());
        $usedDays = $periodDays - $remainDays;
        $remainAmount = ($currentAmount/$periodDays)*$remainDays;
        
        $newAmount = $plan->price;
        $amount = $newAmount - $remainAmount;
        
        // Currency dose not match
        if($plan->getBillableCurrency() != 1) {
            throw new \Exception("Can not change plan: the new plan is lower than current plan");
        }
        
        // New amount id lower than old amount
        if($amount > 0) {
            throw new \Exception("Can not change plan: the new plan is lower than current plan");
        }
        
        var_dump($amount);
        die();
        //$currentSubscription->markAsCancelled();
        //
        //$subscription = $user->createSubscription($plan, $this);
        //$subscription->charge($this);
        //
        //return $subscription;
    }
    
    /**
     * Get subscription invoices.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function getInvoices($subscriptionId)
    {
        $invoices = [];
        
        $sql =<<<EOF
            SELECT * from transactions WHERE subscription_id='{$subscriptionId}' ORDER BY created_at DESC;
EOF;

        $ret = $this->db->query($sql);
        
        while($row = $ret->fetchArray(SQLITE3_ASSOC) ) {
            $invoices[] = new InvoiceParam([
                'time' => $row['created_at'],
                'amount' => $row['price'] . " (" .$row['currency']. ")",
                'description' => $row['description'],
                'status' => $row['status']
            ]);
        }        
        return $invoices;
    }
    
    /**
     * Top-up subscription.
     *
     * @param  Subscription    $subscription
     * @return Boolean
     */
    public function topUp($subscription)
    {
        return false;
    }
}