<?php
namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\Subscription;
use Carbon\Carbon;
use Acelle\Cashier\Cashier;
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
                'first_transaction' => true,
            ]),
        ];
        
        $res = $this->coinPaymentsAPI->CreateSimpleTransaction($options);
        
        if ($res["error"] !== 'ok') {
            throw new \Exception($res["error"]);
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
    public function getInitTransaction($subscription)
    {
        $metadata = $subscription->getMetadata();
        
        if (!isset($metadata['txn_id'])) {
            return null;
        }
        
        // get transaction id exists
        $res = $this->coinPaymentsAPI->GetTxInfoSingle($metadata['txn_id'], 1);
        
        if ($res["error"] !== 'ok') {
            throw new \Exception($res["Can not find remote transaction tnx_id"]);
        } else {
            return $res["result"];
        }
    }
    
    /**
     * Get remote transaction.
     *
     * @return Boolean
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
     * @return Boolean
     */
    public function getTransactionRemoteInfo($txn_id)
    {
        $res = $this->coinPaymentsAPI->GetTxInfoSingle($txn_id, 1);
        
        if ($res["error"] !== 'ok') {
            throw new \Exception($res["Can not find remote transaction tnx_id"]);
        }
        
        return $res['result'];
    }
    
    /**
     * Get remote transaction.
     *
     * @return Boolean
     */
    public function getTransaction($subscription)
    {
        $transactions = $this->getTransactions($subscription);
        
        if (empty($transactions)) {
            return null;
        }
        
        $transaction = end($transactions);
        
        if (isset($transaction['txn_id'])) {
            $transaction['remote'] = $this->getTransactionRemoteInfo($transaction['txn_id']);
        }
        
        return $transaction;
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
        } else if ($subscription->isActive()) {
            // get lastest transaction
            $transaction = $this->getTransaction($subscription);
                       
            if (isset($transaction['force_status']) && $transaction['force_status'] == 'active') {
                $active = true;
            } else {
                $active = $transaction['remote']['status'] == 100; 
            }
        
            if ($active) {
                $subscription->ends_at = \Carbon\Carbon::createFromTimestamp($transaction['periodEndsAt']);
                
                if (isset($transaction["new_plan_id"])) {
                    $subscription->plan_id = $transaction["new_plan_id"];
                }
            }
        }
        
        // current_period_ends_at is always ends_at
        $subscription->current_period_ends_at = $subscription->ends_at;
        
        $subscription->save();
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
        $metadata = $subscription->getMetadata();
        $transactions = isset($metadata['transactions']) ? $metadata['transactions'] : [];
        
        
        // calc result
        $amount = $subscription->plan->getBillableAmount();
        $endsAt = $subscription->nextPeriod()->timestamp;
        
        $options = [
            'currency1' => $subscription->plan->getBillableCurrency(),
            'currency2' => config('cashier.gateways.coinpayments.fields.receive_currency'),
            'amount' => $subscription->plan->getBillableAmount(),
            'item_name' => trans('cashier::messages.coinpayments.renew_plan_desc', [
                'plan' => $subscription->plan->getBillableName(),
            ]),
            'item_number' => $subscription->uid,
            'buyer_email' => $subscription->user->getBillableEmail(),
            'custom' => json_encode([
                'createdAt' => $subscription->created_at->timestamp,
                'periodEndsAt' => $endsAt,
                'amount' => $subscription->plan->getBillableFormattedPrice(),
                'first_transaction' => false,
            ]),
        ];
        
        // if amount == 0
        if ($amount <= 0) {
            $options['periodEndsAt'] = $subscription->current_period_ends_at->timestamp;
            $options['force_status'] = 'active';
            $transactions[] = $options;        
            $subscription->updateMetadata(['transactions' => $transactions]);
            return;
        }
        
        $res = $this->coinPaymentsAPI->CreateSimpleTransaction($options);
        
        if ($res["error"] !== 'ok') {
            throw new \Exception($res["error"]);
        }
        
        $transaction = $res["result"];
        
        // save transactio
        
        
        $options['periodEndsAt'] = $subscription->current_period_ends_at->timestamp;
        $options['txn_id'] = $transaction["txn_id"];
        $options['checkout_url'] = $transaction["checkout_url"];
        $options['status_url'] = $transaction["status_url"];
        $options['qrcode_url'] = $transaction["qrcode_url"];
        
        $transactions[] = $options;
        
        $subscription->updateMetadata(['transactions' => $transactions]);
    }
    
    /**
     * Renew subscription plan.
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
        $metadata = $subscription->getMetadata();
        $transactions = isset($metadata['transactions']) ? $metadata['transactions'] : [];
        
        // calc result
        $result = Cashier::calcChangePlan($subscription, $newPlan);
        $amount = $result["amount"];
        $endsAt = $result["endsAt"];
        
        $newPlan->price = $amount;
        
        $options = [
            'currency1' => $newPlan->getBillableCurrency(),
            'currency2' => config('cashier.gateways.coinpayments.fields.receive_currency'),
            'amount' => $newPlan->getBillableAmount(),
            'item_name' => trans('cashier::messages.coinpayments.change_plan_to', [
                'current_plan' => $subscription->plan->getBillableName(),
                'new_plan' => $newPlan->getBillableName(),
            ]),
            'item_number' => $subscription->uid,
            'buyer_email' => $subscription->user->getBillableEmail(),
            'custom' => json_encode([
                'createdAt' => \Carbon\Carbon::now()->timestamp,
                'periodEndsAt' => $endsAt->timestamp,
                'amount' => $newPlan->getBillableFormattedPrice(),
                'first_transaction' => false,
            ]),
        ];
        
        if ($amount <= 0) {
            $options['periodEndsAt'] = $endsAt->timestamp;
            $options['new_plan_id'] = $newPlan->getBillableId();
            $options['force_status'] = 'active';
            $transactions[] = $options;        
            $subscription->updateMetadata(['transactions' => $transactions]);
            return;
        }        
        
        $res = $this->coinPaymentsAPI->CreateSimpleTransaction($options);
        
        if ($res["error"] !== 'ok') {
            throw new \Exception($res["error"]);
        }
        
        $transaction = $res["result"];
        
        // save transactio
        $options['periodEndsAt'] = $endsAt->timestamp;
        $options['new_plan_id'] = $newPlan->getBillableId();
        $options['txn_id'] = $transaction["txn_id"];
        $options['checkout_url'] = $transaction["checkout_url"];
        $options['status_url'] = $transaction["status_url"];
        $options['qrcode_url'] = $transaction["qrcode_url"];
        
        $transactions[] = $options;
        
        $subscription->updateMetadata(['transactions' => $transactions]);
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
        
        $transaction = $this->getInitTransaction($subscription);
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
                'status' => ($subscription->isActive() ? 'active' : $this->getTransactionStatus($transaction['status']))
            ]);
        }
        
        // other transactions
        $transactions = $this->getTransactions($subscription);
        foreach($transactions as $tran) {
            $transaction = [];
            
            if (isset($tran['txn_id'])) {
                $transaction = $this->getTransactionRemoteInfo($tran['txn_id']);
            }
            
            $custom = json_decode($tran['custom']);
            $invoices[] = new InvoiceParam([
                'createdAt' => $custom->createdAt,
                'periodEndsAt' => $custom->periodEndsAt,
                'amount' => $custom->amount,
                'description' => $tran['item_name'],
                'status' => (isset($tran['force_status']) ? $tran['force_status'] : $this->getTransactionStatus($transaction['status']))
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
        
        $transaction = $this->getInitTransaction($subscription);
        if ($transaction) {
            $transactions[] = $transaction;
        }
        
        $invoices = [];
        foreach($transactions as $transaction) {
            $invoices[] = $transaction;
        }
        
        // other transactions
        $transactions = $this->getTransactions($subscription);
        foreach($transactions as $tran) {
            if (isset($tran['txn_id'])) {
                $transaction = $this->getTransactionRemoteInfo($tran['txn_id']);
                $invoices[] = $transaction;
            } else {
                $invoices[] = $tran;
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
        
        // set last payment status is arrpoved
        $transactions = $this->getTransactions($subscription);
        if (!empty($transactions)) {
            $transactions[count($transactions) - 1]['force_status'] = 'active';
            $subscription->updateMetadata(['transactions' => $transactions]);
        }
        
        // sync
        $this->sync($subscription);
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
        
        if (isset($transaction['force_status']) && $transaction['force_status'] == 'active') {
            $active = true;
        } else {
            $data = json_decode($transaction['remote']['checkout']['custom'], true);
            $active = $transaction['remote']['status'] == 100;
        }
        
        return isset($transaction) && !$active;
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
        $data = json_decode($transaction['remote']['checkout']['custom'], true);
        
        return trans('cashier::messages.direct.has_transaction_pending', [
            'description' => $transaction['remote']['checkout']['item_name'],
            'amount' => $data['amount'],
            'url' => action('\Acelle\Cashier\Controllers\CoinpaymentsController@pending', [
                'subscription_id' => $subscription->uid,
            ]),
        ]);
    }
    
    /**
     * Get renew url.
     *
     * @return string
     */
    public function getRenewUrl($subscription, $returnUrl='/')
    {
        return action("\Acelle\Cashier\Controllers\\CoinpaymentsController@renew", [
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
        return action("\Acelle\Cashier\Controllers\\CoinpaymentsController@changePlan", [
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
        return action("\Acelle\Cashier\Controllers\\CoinpaymentsController@pending", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }

    public function hasError($subscription) {}
    public function getErrorNotice($subscription) {}
}