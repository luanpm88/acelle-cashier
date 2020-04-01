<?php
namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\Subscription;
use Carbon\Carbon;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Library\CoinPayment\CoinpaymentsAPI;
use Acelle\Cashier\InvoiceParam;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;

class CoinpaymentsPaymentGateway implements PaymentGatewayInterface
{
    const ERROR_PENDING_REJECTED = 'pending-rejected';

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
    public function charge($subscription, $data=[])
    {
        $options = [
            'currency1' => $subscription->plan->getBillableCurrency(),
            'currency2' => config('cashier.gateways.coinpayments.fields.receive_currency'),
            'amount' => $data['amount'],
            'item_name' => $data['desc'],
            'item_number' => $subscription->uid,
            'buyer_email' => $subscription->user->getBillableEmail(),
            'custom' => json_encode([
                'tranaction_uid' => $data['id'],
            ]),
        ];
        
        $res = $this->coinPaymentsAPI->CreateSimpleTransaction($options);
        
        if ($res["error"] !== 'ok') {
            throw new \Exception($res["error"]);
        }        
        
        $transaction = $res["result"];
        
        // // update subscription txn_id
        // $subscription->updateMetadata([
        //     'txn_id' => $transaction["txn_id"],
        //     'checkout_url' => $transaction["checkout_url"],
        //     'status_url' => $transaction["status_url"],
        //     'qrcode_url' => $transaction["qrcode_url"],
        // ]);
        
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
        if ($customer->subscription) {
            $subscription = $customer->subscription;
        } else {
            $subscription = new Subscription();
            $subscription->user_id = $customer->getBillableId();
        } 
        // @todo when is exactly started at?
        $subscription->started_at = \Carbon\Carbon::now();
        
        $subscription->user_id = $customer->getBillableId();
        $subscription->plan_id = $plan->getBillableId();
        $subscription->status = Subscription::STATUS_NEW;
        
        // set dates and save
        $subscription->ends_at = $subscription->getPeriodEndsAt(Carbon::now());
        $subscription->current_period_ends_at = $subscription->ends_at;
        $subscription->save();
        
        // Free plan
        if ($plan->getBillableAmount() == 0) {
            // subscription transaction
            $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_SUCCESS,
                'title' => trans('cashier::messages.transaction.subscribed_to_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice(),
            ]);
            
            // set active
            $subscription->setActive();

            $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBED, [
                'plan' => $plan->getBillableName(),
                'price' => $plan->getBillableFormattedPrice(),
            ]);
        } else {
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBE, [
                'plan' => $plan->getBillableName(),
                'price' => $plan->getBillableFormattedPrice(),
            ]);
        }
        
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
        $transaction = $subscription->subscriptionTransactions()->first();
        return $transaction;
    }

    /**
     * Get last transaction
     *
     * @return boolean
     */
    public function getLastTransaction($subscription) {
        // if has only init transaction
        if ($subscription->subscriptionTransactions()->count() <= 1) {
            return null;
        }
        $transaction = $subscription->subscriptionTransactions()->orderBy('created_at', 'desc')->first();
        return $transaction;
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
    public function updateTransactionRemoteInfo($transaction)
    {
        $data = $transaction->getMetadata();
        // get remote information
        $data['remote'] = $this->getTransactionRemoteInfo($data['txn_id']);
        $transaction->updateMetadata($data);
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
        // if init transaction
        if ($subscription->isPending()) {
            $transaction = $this->getInitTransaction($subscription);
            $this->updateTransactionRemoteInfo($transaction);

            // update description
            $transaction->description = $transaction->getMetadata()['remote']['status_text'];
            $transaction->save();

            if ($transaction->getMetadata()['remote']['status'] == 0) {
                // set active
                $transaction->setSuccess();
                $subscription->setActive();  
                
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
                sleep(1);
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBED, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
            }
        }

        // if renew/change plan transaction
        if ($this->hasPending($subscription)) {
            $transaction = $this->getLastTransaction($subscription);
            $this->updateTransactionRemoteInfo($transaction);

            // update description
            $transaction->description = $transaction->getMetadata()['remote']['status_text'];
            $transaction->save();

            if ($transaction->getMetadata()['remote']['status'] == 0) {
                // set active
                $transaction->setSuccess();
                $this->approvePending($subscription);                
            }
            
            // log
            if ($transaction->type == SubscriptionTransaction::TYPE_RENEW) {
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
                sleep(1);
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_RENEWED, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
            } else {
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $transaction->amount,
                ]);
                sleep(1);
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGED, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $transaction->amount,
                ]);
            }
        }
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
        $transaction = $this->getInitTransaction($subscription);
        $transaction->setSuccess();

        // set active subscription
        $subscription->setActive();

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_APPROVED, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
        sleep(1);
        // add log
        $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBED, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
    }
    
    /**
     * Check for notice.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function hasPending($subscription)
    {
        $transaction = $this->getLastTransaction($subscription);

        return isset($transaction) && $transaction->isPending() && !in_array($transaction->type, [
            SubscriptionTransaction::TYPE_SUBSCRIBE,
        ]);
    }
    
    /**
     * Get notice message.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function getPendingNotice($subscription)
    {
        $transaction = $this->getLastTransaction($subscription);
        
        return trans('cashier::messages.direct.has_transaction_pending', [
            'description' => $subscription->plan->name,
            'amount' => $transaction->amount,
            'url' => action('\Acelle\Cashier\Controllers\CoinpaymentsController@transactionPending', [
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
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($subscription, $returnUrl='/') {
        return action("\Acelle\Cashier\Controllers\CoinpaymentsController@checkout", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }
    
    /**
     * Get change plan url.
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

    public function hasError($subscription) {
        $error_type = $subscription->last_error_type;

        switch ($error_type) {
            case CoinpaymentsPaymentGateway::ERROR_PENDING_REJECTED:
                $transaction = $this->getLastTransaction($subscription);

                return $transaction->isFailed();
            default:
                return false;
        }
    }

    public function getErrorNotice($subscription) {
        $error_type = $subscription->last_error_type;

        switch ($error_type) {
            case CoinpaymentsPaymentGateway::ERROR_PENDING_REJECTED:
                $transaction = $this->getLastTransaction($subscription);
                $reason = isset($transaction->getMetadata()['reject-reason']) ? $transaction->getMetadata()['reject-reason'] : '';
                return trans('cashier::messages.last_payment_failed', ['reason' => $reason]);
            default:
                return '';
        }
    }

    /**
     * Set subscription active if it is pending.
     *
     * @return boolean
     */
    public function approvePending($subscription) {
        $transaction = $this->getLastTransaction($subscription);
        $transaction->setSuccess();

        // check new states
        $subscription->ends_at = $transaction->ends_at;
        
        // period date update
        if ($subscription->current_period_ends_at != $transaction->current_period_ends_at) {
            // save last period
            $subscription->last_period_ends_at = $subscription->current_period_ends_at;
            // set new current period
            $subscription->current_period_ends_at = $transaction->current_period_ends_at;
        }

        // check new plan
        $data = $transaction->getMetadata();
        if (isset($data['plan_id'])) {
            $subscription->plan_id = $data['plan_id'];
        }

        $subscription->save();
    }

    /**
     * Reject renew/change plan pending.
     *
     * @return boolean
     */
    public function rejectPending($subscription, $reason) {
        $transaction = $this->getLastTransaction($subscription);
        $transaction->setFailed();

        // log
        if ($transaction->type == SubscriptionTransaction::TYPE_RENEW) {
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_RENEW_REJECTED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
                'reason' => $reason,
            ]);
        } else {
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_CHANGE_REJECTED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $transaction->amount,
                'reason' => $reason,
            ]);
        }

        // set subscription last_error_type
        $subscription->last_error_type = CoinpaymentsPaymentGateway::ERROR_PENDING_REJECTED;
        $subscription->save();

        // save reason
        $data = $transaction->getMetadata();
        $data['reject-reason'] = $reason;
        $transaction->updateMetadata($data);
    }

    /**
     * Cancel subscription.
     *
     * @return string
     */
    public function cancel($subscription) {
        $subscription->cancel();

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_CANCELLED, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
    }

    /**
     * Cancel now subscription.
     *
     * @return string
     */
    public function cancelNow($subscription) {
        $subscription->cancelNow();

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_CANCELLED_NOW, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
    }

    /**
     * Resume now subscription.
     *
     * @return string
     */
    public function resume($subscription) {
        $subscription->resume();

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_RESUMED, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
    }

    /**
     * Check if use remote subscription.
     *
     * @return void
     */
    public function useRemoteSubscription()
    {
        return false;
    }
}