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
    public $notice;
    
    public function __construct($notice)
    {
        $this->db = new \SQLite3(storage_path('app/direct_payment.sqlite3'));
        $this->notice = $notice;
        
        // Create transaction table if not exist
        $sql =<<<EOF
            CREATE TABLE IF NOT EXISTS transactions
            (ID INTEGER PRIMARY KEY AUTOINCREMENT,
            subscription_id           VARCHAR(255)    NOT NULL,
            price                     REAL            NOT NULL,
            currency                  VARCHAR(255)    NOT NULL,
            status                    CHAR(50)        NOT NULL,
            description               TEXT            NOT NULL,
            data                      TEXT            NOT NULL,
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
     * Format price.
     *
     * @param string
     *
     * @return string
     */
    public function format_price($price, $format = '{PRICE}')
    {
        return str_replace('{PRICE}', self::format_number($price), $format);
    }
    
    /**
     * Create a new subscription.
     *
     * @param  mixed                $token
     * @param  Subscription         $subscription
     * @return void
     */
    public function createSubscription($subscription)
    {
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
        
        if ($amount == 0) {
            $status = Subscription::STATUS_DONE;
        }
        
        $data = json_encode([
            'createdAt' => $subscription->created_at->timestamp,
            'periodEndsAt' => $subscription->ends_at->timestamp,
            'amount' => \Acelle\Library\Tool::format_price($subscription->plan->price, $subscription->plan->currency->format),
            'description' => trans('messages.invoice.subscribe_to_plan', [
                'plan' => $subscription->plan->name,
            ]),
        ]);
        
        // custom amount
        if (isset($options['amount'])) {
            $amount = $options['amount'];
        }
        
        // Create new transaction for payment
        $sql =<<<EOF
            INSERT INTO transactions (subscription_id, price, currency, status, description, created_at, data)  
            VALUES ('{$subscription->uid}', {$amount}, '{$currency}', '{$status}', '{$description}', {$created_at}, '{$data}');
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
        
        $metadata = $subscription->getMetadata();
        if (isset($metadata->transaction_id)) {
            $tid = $metadata->transaction_id;
            $sql =<<<EOF
                SELECT * from transactions WHERE ID='{$tid}' ORDER BY created_at DESC;
EOF;
        } else {
            $sql =<<<EOF
                SELECT * from transactions WHERE subscription_id='{$subscriptionId}' ORDER BY created_at DESC;
EOF;
        }

        $ret = $this->db->query($sql);
        $row = $ret->fetchArray(SQLITE3_ASSOC);
        
        if(!$row) {
            throw new \Exception("Can not find the subscription with id = $subscriptionId");
        }
        
        $subscriptionParam = new SubscriptionParam([
            'status' => $row['status'],
            'amount' => $row['price'],
        ]);
        
        // Update subscription id
        $subscription->updateMetadata(['transaction_id' => $row['ID']]);
        
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
     * Renew subscription.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function renewSubscription($subscription)
    {
        $created_at = \Carbon\Carbon::now()->timestamp;
        $status = Subscription::STATUS_PENDING;
        $description = 'Transaction was created. Waiting for payment...';
        $amount = $subscription->plan->getBillableAmount();
        $currency = $subscription->plan->getBillableCurrency();
        
        $data = json_encode([
            'createdAt' => \Carbon\Carbon::now()->timestamp,
            'periodEndsAt' => $subscription->nextPeriod()->timestamp,
            'amount' => \Acelle\Library\Tool::format_price($subscription->plan->price, $subscription->plan->currency->format),
            'description' => trans('messages.invoice.renew_plan', [
                'plan' => $subscription->plan->name,
            ]),
        ]);
        
        // custom amount
        if (isset($options['amount'])) {
            $amount = $options['amount'];
        }
        
        // Create new transaction for payment
        $sql =<<<EOF
            INSERT INTO transactions (subscription_id, price, currency, status, description, created_at, data)  
            VALUES ('{$subscription->uid}', {$amount}, '{$currency}', '{$status}', '{$description}', {$created_at}, '{$data}');
EOF;
        $act = $this->db->exec($sql);
        if(!$act){
            throw new \Exception($this->db->lastErrorMsg());
        }
    }
    
    /**
     * Swap subscription plan.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function changePlan($user, $newPlan)
    {
        $subscription = $user->subscription();
        
        $created_at = \Carbon\Carbon::now()->timestamp;
        $status = Subscription::STATUS_PENDING;
        $description = 'Transaction was created. Waiting for payment...';
        $amount = $subscription->plan->getBillableAmount();
        $currency = $subscription->plan->getBillableCurrency();
        
        $data = json_encode([
            'createdAt' => \Carbon\Carbon::now()->timestamp,
            'planId' => $newPlan->getBillableId(),
            'periodEndsAt' => $subscription->ends_at->timestamp,
            'amount' => \Acelle\Library\Tool::format_price($user->calcChangePlan($newPlan)['amount'], $newPlan->currency->format),
            'description' => trans('messages.invoice.change_plan', [
                'current_plan' => $subscription->plan->name,
                'new_plan' => $newPlan->name,
            ]),
        ]);
        
        // custom amount
        if (isset($options['amount'])) {
            $amount = $options['amount'];
        }
        
        // Create new transaction for payment
        $sql =<<<EOF
            INSERT INTO transactions (subscription_id, price, currency, status, description, created_at, data)  
            VALUES ('{$subscription->uid}', {$amount}, '{$currency}', '{$status}', '{$description}', {$created_at}, '{$data}');
EOF;
        $act = $this->db->exec($sql);
        if(!$act){
            throw new \Exception($this->db->lastErrorMsg());
        }
        
        // mark the payment is not claimed
        $subscription->payment_claimed = false;
        $subscription->save();
        
        return $subscription;
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
            $data = json_decode($row['data'], true);
            $invoices[] = new InvoiceParam([
                'createdAt' => $data['createdAt'],
                'periodEndsAt' => $data['periodEndsAt'],
                'amount' => $data['amount'],
                'description' => $data['description'],
                'status' => $row['status'],
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
    
    /**
     * Get subscription raw invoices.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function getRawInvoices($subscriptionId)
    {
        $invoices = [];
        
        $sql =<<<EOF
            SELECT * from transactions WHERE subscription_id='{$subscriptionId}' ORDER BY created_at DESC;
EOF;

        $ret = $this->db->query($sql);
        
        while($row = $ret->fetchArray(SQLITE3_ASSOC) ) {
            $data = json_decode($row['data'], true);
            $invoices[] = new InvoiceParam([
                'createdAt' => $data['createdAt'],
                'periodEndsAt' => $data['periodEndsAt'],
                'amount' => $data['amount'],
                'description' => $data['description'],
                'status' => $row['status'],
            ]);
        }        
        return $invoices;
    }
    
    /**
     * Check if subscription has future payment pending.
     *
     * @param  Subscription    $subscription
     * @return Boolean
     */
    public function checkPendingPaymentForFuture($subscription)
    {
        $subscriptionId = $subscription->uid;
        
        // Check if has current transaction is current subscription
        $metadata = $subscription->getMetadata();
        if (!isset($metadata->transaction_id)) {
            $metadataTid = 'empty';
        } else {
            $metadataTid = $metadata->transaction_id;
        }
        
        // Find newest transaction that maybe topup transaction
        $sql =<<<EOF
                SELECT * from transactions WHERE subscription_id='{$subscriptionId}' ORDER BY created_at DESC;
EOF;
        $ret = $this->db->query($sql);
        $row = $ret->fetchArray(SQLITE3_ASSOC);
        
        if(!$row) {
            return false;
        }
        
        // found
        if ($row && $row['ID'] != $metadataTid) {
            if ($row['status'] == Subscription::STATUS_DONE) {
                $data = json_decode($row["data"], true);
                
                $subscription->updateMetadata(['transaction_id' => $row['ID']]);
                if (isset($data['periodEndsAt'])) {
                    $subscription->ends_at = \Carbon\Carbon::createFromTimestamp($data['periodEndsAt']);
                }
                
                if (isset($data['planId'])) {
                    $subscription->plan_id = $data['planId'];
                }
                
                $subscription->save();
            }
            
            if ($row['status'] == Subscription::STATUS_PENDING) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Allow admin update payment status without service without payment.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function setDone($subscription)
    {
        $status = Subscription::STATUS_DONE;
        
        // Create new transaction for payment
        $sql =<<<EOF
            UPDATE transactions SET status='{$status}'  
            WHERE subscription_id='{$subscription->uid}';
EOF;
        $act = $this->db->exec($sql);
        if(!$act){
            throw new \Exception($this->db->lastErrorMsg());
        }
    }
    
    /**
     * Approve future invoice
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function approvePendingInvoice($subscription)
    {
        $subscriptionId = $subscription->uid;
        
        // Check if has current transaction is current subscription
        $metadata = $subscription->getMetadata();
        if (!isset($metadata->transaction_id)) {
            return null;
        }
        
        // Find newest transaction that maybe topup transaction
        $sql =<<<EOF
                SELECT * from transactions WHERE subscription_id='{$subscriptionId}' ORDER BY created_at DESC;
EOF;
        $ret = $this->db->query($sql);
        $row = $ret->fetchArray(SQLITE3_ASSOC);
        
        if(!$row) {
            throw new \Exception("Can not find the subscription with id = $subscriptionId");
        }
        
        
        $status = Subscription::STATUS_DONE;
        
        if ($row && $row['ID'] != $metadata->transaction_id && $row['status'] == Subscription::STATUS_PENDING) {
            $tid = $row['ID'];
            // Create new transaction for payment
            $sql =<<<EOF
                UPDATE transactions SET status='{$status}'  
                WHERE ID='{$tid}';
EOF;
            $act = $this->db->exec($sql);
            if(!$act){
                throw new \Exception($this->db->lastErrorMsg());
            }
        }
    }
}