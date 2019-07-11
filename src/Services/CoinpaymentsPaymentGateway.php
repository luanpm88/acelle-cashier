<?php
namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\Subscription;
use Carbon\Carbon;
use Acelle\Cashier\Library\CoinPayment\CoinpaymentsAPI;
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
     * create new transaction
     *
     * @return void
     */
    public function charge($subscription)
    {
        $options = [
            'currency1' => $subscription->plan->getBillableCurrency(),
            'currency2' => config('cashier.gateways.coinpayments.fields.receive_currency'),
            'amount' => $subscription->plan->getBillableAmount(),
            'item_name' => trans('cashier::messages.coinpayments.subscribe_to_plan', [
                'plan' => $subscription->plan->getBillableName(),
            ]),
            'item_number' => $subscription->uid,
            'buyer_email' => $subscription->user->getBillableEmail(),
            'custom' => json_encode([
                'createdAt' => $subscription->created_at->timestamp,
                'periodEndsAt' => $subscription->current_period_ends_at->timestamp,
                'amount' => $subscription->plan->getBillableFormattedPrice(),
            ]),
        ];
        
        $res = $this->coinPaymentsAPI->CreateSimpleTransaction($options);
        
        if ($res["error"] !== 'ok') {
            throw new \Exception($transaction["error"]);
        }
        
        $transaction = $res["result"];
        
        // update subscription txn_id
        $subscription->updateMetadata([
            'txn_id' => $transaction["txn_id"],
            'checkout_url' => $transaction["checkout_url"],
            'status_url' => $transaction["status_url"],
            'qrcode_url' => $transaction["qrcode_url"],
        ]);
        
        return $transaction;
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
     * Get remote transaction.
     *
     * @return Boolean
     */
    public function getTransaction($subscription)
    {
        $metadata = $subscription->getMetadata();
        
        if (!property_exists($metadata, 'txn_id')) {
            return null;
        }
        
        // get transaction id exists
        $res = $this->coinPaymentsAPI->GetTxInfoSingle($metadata->txn_id, 1);
        
        if ($res["error"] !== 'ok') {
            return null;
        } else {
            return $res["result"];
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
        if (!$subscription->isEnded() && !$subscription->isActive()) {
            $transaction = $this->getTransaction($subscription);
            
            if ($transaction["status"] >= 0) {
                $subscription->status = Subscription::STATUS_PENDING;
            }
            
            if ($transaction["status"] == 100) {
                $subscription->status = Subscription::STATUS_ACTIVE;
            }
            
            // end subscription if transaction is failed
            if ($transaction["status"] < 0) {
                $subscription->status = Subscription::STATUS_ENDED;
                $subscription->ends_at = $found["time_expires"];
            }
        }
        
        // current_period_ends_at is always ends_at
        $subscription->current_period_ends_at = $subscription->ends_at;
        
        $subscription->save();
        
        return $transaction;
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
    public function cancelNow($subscription)
    {
        $subscription->ends_at = \Carbon\Carbon::now();
        $subscription->status = Subscription::STATUS_ENDED;
        
        $this->sync($subscription);
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
    public function getInvoices($subscription)
    {
        $transactions = [];
        
        $transaction = $this->getTransaction($subscription);
        if ($transaction) {
            $transactions[] = $transaction;
        }
        
        $invoices = [];
        foreach($transactions as $transaction) {
            $custom = json_decode($transaction['checkout']['custom']);
            $invoices[] = new InvoiceParam([
                'createdAt' => $transaction['time_created'],
                'periodEndsAt' => $custom->periodEndsAt,
                'amount' => $custom->amount,
                'description' => $transaction['checkout']['item_name'],
                'status' => ($subscription->isActive() ? 'approved' : $this->getTransactionStatus($transaction['status']))
            ]);
        }
        
        return $invoices;
    }
    
    /**
     * Get subscription raw invoices.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function getRawInvoices($subscription)
    {
        $transactions = [];
        
        $transaction = $this->getTransaction($subscription);
        if ($transaction) {
            $transactions[] = $transaction;
        }
        
        $invoices = [];
        foreach($transactions as $transaction) {
            $invoices[] = $transaction;
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
    
    /**
     * Get all coins
     *
     * @return date
     */
    public function getRates()
    {
        return $this->coinPaymentsAPI->getRates();
    }
    
    /**
     * Force check subscription is active.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function setActive($subscription)
    {
        $subscription->status = Subscription::STATUS_ACTIVE;
        
        // sync
        $this->sync($subscription);
    }
}