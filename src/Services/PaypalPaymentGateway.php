<?php

namespace Acelle\Cashier\Services;

use Acelle\Cashier\Cashier;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\Subscription;
use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\InvoiceParam;
use Carbon\Carbon;
use Sample\PayPalClient;
use PayPalCheckoutSdk\Orders\OrdersGetRequest;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;

class PaypalPaymentGateway implements PaymentGatewayInterface
{
    public $client_id;
    public $secret;
    public $client;
    public $environment;
    
    public function __construct($environment, $client_id, $secret)
    {
        $this->environment = $environment;
        $this->client_id = $client_id;
        $this->secret = $secret;

        if ($this->environment == 'sandbox') {
            $this->client = new PayPalHttpClient(new SandboxEnvironment($this->client_id, $this->secret));        
        } else {
            $this->client = new PayPalHttpClient(new ProductionEnvironment($this->client_id, $this->secret));    
        }
    }

    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function validate()
    {
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
        
        // Free plan
        if ($plan->getBillableAmount() == 0) {
            $subscription->status = Subscription::STATUS_ACTIVE;
        }
        
        $subscription->save();        
        return $subscription;
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
        // Check payment status
        $response = $this->client->execute(new OrdersGetRequest($options['orderID']));

        /**
         *Enable the following line to print complete response as JSON.
        */
        //print json_encode($response->result);
        print "Status Code: {$response->statusCode}\n";
        print "Status: {$response->result->status}\n";
        print "Order ID: {$response->result->id}\n";
        print "Intent: {$response->result->intent}\n";
        print "Links:\n";
        foreach($response->result->links as $link)
        {
        print "\t{$link->rel}: {$link->href}\tCall Type: {$link->method}\n";
        }
        // 4. Save the transaction in your database. Implement logic to save transaction to your database for future reference.
        print "Gross Amount: {$response->result->purchase_units[0]->amount->currency_code} {$response->result->purchase_units[0]->amount->value}\n";

        // To print the whole response body, uncomment the following line
        // echo json_encode($response->result, JSON_PRETTY_PRINT);

        // if success
        if ($response->statusCode == 200 && $response->result->status == 'COMPLETED') {
            return true;
        } else {
            throw new \Exception('Something went wrong:' . json_encode($response->result));
        }
    }














    /**
     * Get transaction by subscription id.
     *
     * @return void
     */
    public function getTransaction($subscription)
    {
        
    }
    
    /**
     * Get transaction by subscription id.
     *
     * @return void
     */
    public function findTransactionBySubscription($subscription)
    {
        
    }
    
    /**
     * Get transaction by subscription id.
     *
     * @return void
     */
    public function getInitTransaction($subscription)
    {
        $sql =<<<EOF
                SELECT * from transactions WHERE subscription_id='{$subscription->uid}' ORDER BY created_at ASC;
EOF;

        $ret = $this->db->query($sql);
        $row = $ret->fetchArray(SQLITE3_ASSOC);
        
        return $row;
    }
    
    
    /**
     * Claim payment.
     *
     * @return void
     */
    public function pendingClaim($transaction_id)
    {
        $claimed = true;
        // Create new transaction for payment
        $sql =<<<EOF
            UPDATE transactions SET payment_claimed='{$claimed}'  
            WHERE ID='{$transaction_id}';
EOF;
        $act = $this->db->exec($sql);
        if(!$act){
            throw new \Exception($this->db->lastErrorMsg());
        }
    }
    
    /**
     * Unclaim payment.
     *
     * @return void
     */
    public function pendingUnclaim($transaction_id)
    {
        $claimed = false;
        // Create new transaction for payment
        $sql =<<<EOF
            UPDATE transactions SET payment_claimed='{$claimed}'  
            WHERE ID='{$transaction_id}';
EOF;
        $act = $this->db->exec($sql);
        if(!$act){
            throw new \Exception($this->db->lastErrorMsg());
        }
    }
    
    /**
     * Allow admin approve pending subscription.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function setActive($subscription)
    {
        if ($this->hasPending($subscription)) {
            $this->setSubscriptionAcive($subscription);
        } else {
            $this->setPendingAcive($subscription);
        }
    }
    
    /**
     * Allow admin approve pending subscription.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function setSubscriptionAcive($subscription)
    {
        $status = Subscription::STATUS_ACTIVE;
        
        // Create new transaction for payment
        $sql =<<<EOF
            UPDATE transactions SET status='{$status}'  
            WHERE subscription_id='{$subscription->uid}';
EOF;
        $act = $this->db->exec($sql);
        if(!$act){
            throw new \Exception($this->db->lastErrorMsg());
        }
        
        $this->sync($subscription);
    }
    
    /**
     * Allow admin approve pending subscription.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function setPendingAcive($subscription)
    {
        $transaction = $this->getTransaction($subscription);
        
        $status = Subscription::STATUS_ACTIVE;
        
        // Create new transaction for payment
        $sql =<<<EOF
            UPDATE transactions SET status='{$status}'  
            WHERE ID='{$transaction['ID']}';
EOF;
        $act = $this->db->exec($sql);
        if(!$act){
            throw new \Exception($this->db->lastErrorMsg());
        }
        
        $this->sync($subscription);
    }
    
    /**
     * Check pending transaction.
     *
     * @return void
     */
    public function checkPendingPayment()
    {
        if(!$this->db) {
            throw new \Exception($this->db->lastErrorMsg());
        } else {
            return true;
        }
    }
    
    /**
     * Retrieve subscription param.
     *
     * @param  Subscription  $subscription
     * @return SubscriptionParam
     */
    public function sync($subscription)
    {
        if ($subscription->isPending() || $subscription->isNew()) {
            $transaction = $this->getInitTransaction($subscription);
            
            if ($transaction['status'] == 'pending') {
                $subscription->status = Subscription::STATUS_PENDING;
            }
            
            if ($transaction['status'] == 'active') {
                $subscription->status = Subscription::STATUS_ACTIVE;
            }
            
            if ($transaction['status'] == 'ended') {
                $subscription->status = Subscription::STATUS_ENDED;
            }
        } else if ($subscription->isActive()) {
            // get lastest transaction
            $transaction = $this->getTransaction($subscription);
            
            // set active if price is free
            if ($transaction['status'] == 'pending' && $transaction['price'] == 0) {
                $this->setActive($subscription);
                
                $transaction = $this->getTransaction($subscription);
            }
            
            if ($transaction['status'] == 'active') {
                $data = json_decode($transaction['data'], true);
                
                $subscription->ends_at = \Carbon\Carbon::createFromTimestamp($data['periodEndsAt']);
                
                if (isset($data["new_plan_id"])) {
                    $subscription->plan_id = $data["new_plan_id"];
                }
            }
        }
        
        // current_period_ends_at is always ends_at
        $subscription->current_period_ends_at = $subscription->ends_at;
        
        $subscription->save();
        
//        $subscription = Subscription::findByUid($subscriptionId);
//        
//        // Check if plan is free
//        if ($subscription->plan->getBillableAmount() == 0) {
//            return new SubscriptionParam([
//                'status' => Subscription::STATUS_DONE,
//                'createdAt' => $subscription->created_at,
//            ]);
//        }
//        
//        $metadata = $subscription->getMetadata();
//        if (isset($metadata->transaction_id)) {
//            $tid = $metadata->transaction_id;
//            $sql =<<<EOF
//                SELECT * from transactions WHERE ID='{$tid}' ORDER BY created_at DESC;
//EOF;
//        } else {
//            $sql =<<<EOF
//                SELECT * from transactions WHERE subscription_id='{$subscriptionId}' ORDER BY created_at DESC;
//EOF;
//        }
//
//        $ret = $this->db->query($sql);
//        $row = $ret->fetchArray(SQLITE3_ASSOC);
//        
//        if(!$row) {
//            throw new \Exception("Can not find the subscription with id = $subscriptionId");
//        }
//        
//        $subscriptionParam = new SubscriptionParam([
//            'status' => $row['status'],
//            'amount' => $row['price'],
//        ]);
//        
//        // Update subscription id
//        $subscription->updateMetadata(['transaction_id' => $row['ID']]);
//        
//        return $subscriptionParam;
    }
    
    /**
     * Resume subscription.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function cancelNow($subscription)
    {
        $subscription->setEnded();
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
     * Get payment guiline message.
     *
     * @return Boolean
     */
    public function getPaymentInstruction()
    {
        if (config('cashier.gateways.direct.fields.payment_instruction')) {
            return config('cashier.gateways.direct.fields.payment_instruction');
        } else {
            return trans('cashier::messages.direct.payment_instruction.demo');
        }
            
    }

    /**
     * Get payment confirmation message.
     *
     * @return Boolean
     */
    public function getPaymentConfirmationMessage()
    {
        if (config('cashier.gateways.direct.fields.confirmation_message')) {
            return config('cashier.gateways.direct.fields.confirmation_message');
        } else {
            return trans('cashier::messages.direct.confirmation_message.demo');
        }
            
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
    public function changePlan($subscription, $newPlan)
    {
        $created_at = \Carbon\Carbon::now()->timestamp;
        $status = Subscription::STATUS_PENDING;
        $description = 'Transaction was created. Waiting for payment...';        
        $currency = $subscription->plan->getBillableCurrency();
        
        
        // calc result
        $result = Cashier::calcChangePlan($subscription, $newPlan);
        $amount = $result["amount"];
        $endsAt = $result["endsAt"];
        
        $newPlan->price = $amount;
        
        if ($amount < 0) {
            throw new \Exception(trans('cashier::messages.direct.can_not_change_to_lower_plan'));
        }
        
        $data = json_encode([
            'createdAt' => \Carbon\Carbon::now()->timestamp,
            'planId' => $newPlan->getBillableId(),
            'periodEndsAt' => $endsAt->timestamp,
            'amount' => $newPlan->getBillableFormattedPrice(),
            'new_plan_id' => $newPlan->getBillableId(),
            'description' => trans('cashier::messages.direct.change_plan_to', [
                'current_plan' => $subscription->plan->getBillableName(),
                'new_plan' => $newPlan->getBillableName(),
            ]),
        ]);
        
        // Create new transaction for payment
        $sql =<<<EOF
            INSERT INTO transactions (subscription_id, price, currency, status, description, created_at, data, payment_claimed)  
            VALUES ('{$subscription->uid}', {$amount}, '{$currency}', '{$status}', '{$description}', {$created_at}, '{$data}', 0);
EOF;
        $act = $this->db->exec($sql);
        if(!$act){
            throw new \Exception($this->db->lastErrorMsg());
        }
        
        $subscription->save();
        
        return $subscription;
    }
    
    /**
     * Renew subscription plan.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function renew($subscription)
    {
        $created_at = \Carbon\Carbon::now()->timestamp;
        $status = Subscription::STATUS_PENDING;
        $description = 'Transaction was created. Waiting for payment...';        
        $currency = $subscription->plan->getBillableCurrency();
        
        
        // calc result
        $amount = $subscription->plan->getBillableAmount();
        $endsAt = $subscription->nextPeriod()->timestamp;
        
        $data = json_encode([
            'createdAt' => \Carbon\Carbon::now()->timestamp,
            'planId' => $subscription->plan->getBillableId(),
            'periodEndsAt' => $endsAt,
            'amount' => $subscription->plan->getBillableFormattedPrice(),
            'description' => trans('cashier::messages.direct.renew_plan_desc', [
                'plan' => $subscription->plan->getBillableName(),
            ]),
        ]);
        
        // Create new transaction for payment
        $sql =<<<EOF
            INSERT INTO transactions (subscription_id, price, currency, status, description, created_at, data, payment_claimed)  
            VALUES ('{$subscription->uid}', {$amount}, '{$currency}', '{$status}', '{$description}', {$created_at}, '{$data}', 0);
EOF;
        $act = $this->db->exec($sql);
        if(!$act){
            throw new \Exception($this->db->lastErrorMsg());
        }
        
        $subscription->save();
        
        return $subscription;
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
    public function getRawInvoices($subscription)
    {
        $invoices = [];
        
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
        ss;
    }
    
    /**
     * Approve future invoice
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function approvePendingInvoice($subscription)
    {
        ss;
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
     * Get notice message.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function getPendingNotice($subscription)
    {
        return false;
    }
    
    /**
     * Get renew url.
     *
     * @return string
     */
    public function getRenewUrl($subscription, $returnUrl='/')
    {
        return action("\Acelle\Cashier\Controllers\\PaypalController@renew", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }
    
    /**
     * Get renew url.
     *
     * @return string
     */
    public function getChangePlanUrl($subscription, $plan_id, $returnUrl='/')
    {
        return action("\Acelle\Cashier\Controllers\\PaypalController@changePlan", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
            'plan_id' => $plan_id,
        ]);
    }
    
    /**
     * Get renew url.
     *
     * @return string
     */
    public function getPendingUrl($subscription, $returnUrl='/')
    {
        return action("\Acelle\Cashier\Controllers\\PaypalController@pending", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }
}