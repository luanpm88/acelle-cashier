<?php
namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\Subscription;
use Carbon\Carbon;
use Acelle\Library\CoinPayment\CoinpaymentsAPI;
use Acelle\Cashier\InvoiceParam;

class CoinpaymentsPaymentGateway implements PaymentGatewayInterface
{
    public $coinPaymentsAPI;
    
    // Contruction
    public function __construct($merchantId, $publicKey, $privateKey, $ipnSecret)
    {   
        $this->coinPaymentsAPI = new CoinpaymentsAPI($privateKey, $publicKey, 'json'); // new CoinPayments($privateKey, $publicKey, $merchantId, $ipnSecret, null);
    }
    
    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function validate()
    {
        $info = $this->coinPaymentsAPI->getBasicInfo();
        
        if (isset($info["error"]) && $info["error"] != "ok") {
            throw new \Exception($info["error"]);
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
     * Create a new subscription.
     *
     * @param  mixed                $token
     * @param  Subscription         $subscription
     * @return void
     */
    public function createSubscription($customer, $plan)
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
        $subscription->save();
        
        // Save some transaction info to subscription metadata
        $metadata = $subscription->getMetadata();
        $transactions = isset($metadata->transactions) ? $metadata->transactions : [];        
        $tid = 'Transaction ID: ' . uniqid();        
        $transactions[] = [
            'id' => $tid,
            'createdAt' => $subscription->created_at->timestamp,
			'periodEndsAt' => $subscription->ends_at->timestamp,
			'amount' => \Acelle\Library\Tool::format_price($subscription->plan->price, $subscription->plan->currency->format),
        ];        
        $subscription->updateMetadata(['transactions' => $transactions]);
             
        return $subscription;
    }
    
    /**
     * Create a new subscriptionParam.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function charge($subscription)
    {
    }
    
    /**
     * Check if customer has valid card.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function billableUserHasCard($user)
    {
        return false;
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
            $found = $this->coinPaymentsAPI->GetTxInfoSingle($metadata->transaction_id)['result'];
            $transactionId = $metadata->transaction_id;
        } else {        
            $transactions = $this->coinPaymentsAPI->GetTxIds(["limit" => 100]);
            $found = null;
            $transactionId = null;
            foreach($transactions["result"] as $transaction) {
                $result = $this->coinPaymentsAPI->GetTxInfoSingle($transaction, 1)["result"];
                $id = $result["checkout"]["item_number"];
                if ($subscriptionId == $id) {
                    $found = $result;
                    $transactionId = $transaction;
                    break;
                }
            }
        }
        
        if (!isset($found)) {
            throw new \Exception('Subscription can not be found');
        }
        
        // Update subscription id
        $subscription->updateMetadata(['transaction_id' => $transactionId]);
        
        $subscriptionParam = new SubscriptionParam([
            'createdAt' => $found["time_created"],
        ]);
        
        if ($found["status"] >= 0) {
            $subscriptionParam->status = Subscription::STATUS_PENDING;
        }
        
        if ($found["status"] == 100) {
            $subscriptionParam->status = Subscription::STATUS_DONE;
        }
        
        // end subscription if transaction is failed
        if ($found["status"] < 0) {
            $subscriptionParam->endsAt = $found["time_expires"];
        }
        
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
        // @already cancel at end of period
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
        
    }
    
    /**
     * Change subscription plan.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function changePlan($subscriptionId, $plan)
    {
        $currentSubscription = $user->subscription();
        $currentSubscription->markAsCancelled();
        
        $subscription = $user->createSubscription($plan, $this);        
        $subscription->charge($this);
        
        return $subscription;
    }
    
    /**
     * Convert transaction status integer to string.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function getTransactionStatus($number)
    {
        $statuses = [
            -2 => 'Refund / Reversal',
            -1 => 'Cancelled / Timed Out',
            0 => 'Waiting',
            1 => 'Coin Confirmed',
            2 => 'Queued',
            3 => 'PayPal Pending',
            100 => 'Complete',
        ];
        
        return $statuses[$number];
    }
    
    /**
     * Get subscription invoices.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function getInvoices($subscriptionId)
    {
        $subscription = Subscription::findByUid($subscriptionId);
        $transactions = $this->coinPaymentsAPI->GetTxIds(["limit" => 100]);
        
        $invoices = [];
        if (isset($transactions["result"])) {
            foreach($transactions["result"] as $transaction) {
                $result = $this->coinPaymentsAPI->GetTxInfoSingle($transaction, 1)["result"];
                $id = $result["checkout"]["item_number"];            
                if ($subscriptionId == $id) {
                    // $data = json_decode($result["checkout"]["item_desc"], true);
                    $tid = $result["checkout"]["item_desc"];
                    $data = $neededObject = array_filter(
                        $subscription->getMetadata()->transactions,
                        function ($e) use (&$tid) {
                            return $e->id == $tid;
                        }
                    );
                    $data = reset($data);
                    
                    $invoices[] = new InvoiceParam([
                        'createdAt' => $data->createdAt,
                        'periodEndsAt' => $data->periodEndsAt,
                        'amount' => $data->amount,
                        'description' => $result["checkout"]["item_name"],
                        'status' => $this->getTransactionStatus($result['status'])
                    ]);
                }
            }
        }
        
        return $invoices;
    }
    
    /**
     * Get subscription raw invoices.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function getRawInvoices($subscriptionId)
    {
        $transactions = $this->coinPaymentsAPI->GetTxIds(["limit" => 100]);
        
        $invoices = [];
        if (isset($transactions["result"])) {
            foreach($transactions["result"] as $transaction) {
                $result = $this->coinPaymentsAPI->GetTxInfoSingle($transaction, 1)["result"];
                $id = $result["checkout"]["item_number"];            
                if ($subscriptionId == $id) {
                    $invoices[] = $result;
                }
            }
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
        // Check if has current transaction is current subscription
        $metadata = $subscription->getMetadata();
        if (!isset($metadata->transaction_id)) {
            $metadataTid = 'empty';
        } else {
            $metadataTid = $metadata->transaction_id;
        }
        
        // Find newest transaction that maybe topup transaction
        $transactions = $this->coinPaymentsAPI->GetTxIds(["limit" => 100]);        
        $found = null;
        $transactionId = null;
        foreach($transactions["result"] as $transaction) {
            $result = $this->coinPaymentsAPI->GetTxInfoSingle($transaction, 1)["result"];            
            $id = $result["checkout"]["item_number"];
            if ($subscription->uid == $id) {
                $found = $result;
                $transactionId = $transaction;
                break;
            }
        }
        
        // found
        if (isset($found) && $transactionId != $metadataTid) {
            if ($found['status'] == 100) {
                $data = json_decode($found["checkout"]["item_desc"], true);
                
                $subscription->updateMetadata(['transaction_id' => $transactionId]);
                if (isset($data['periodEndsAt'])) {
                    $subscription->ends_at = \Carbon\Carbon::createFromTimestamp($data['periodEndsAt']);
                }
                
                if (isset($data['planId'])) {
                    $subscription->plan_id = $data['planId'];
                }
                
                $subscription->save();
            }
            
            if ($found['status'] != 100 && $found['status'] >= 0) {
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
        throw new \Exception('The Payment service dose not support this feature!');
    }
    
    /**
     * Approve future invoice
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function approvePendingInvoice($subscription)
    {
        throw new \Exception('The Payment service dose not support this feature!');
    }
}