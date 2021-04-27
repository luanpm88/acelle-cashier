<?php
namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Carbon\Carbon;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Library\CoinPayment\CoinpaymentsAPI;

class CoinpaymentsPaymentGateway implements PaymentGatewayInterface
{
    public $coinPaymentsAPI;
    public $receive_currency;
    
    // Contruction
    public function __construct($merchantId, $publicKey, $privateKey, $ipnSecret, $receive_currency)
    {
        $this->receive_currency = $receive_currency;
        $this->coinPaymentsAPI = new CoinpaymentsAPI($privateKey, $publicKey, 'json'); // new CoinPayments($privateKey, $publicKey, $merchantId, $ipnSecret, null);

        \Carbon\Carbon::setToStringFormat('jS \o\f F');
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

    public function supportsAutoBilling() {
        return false;
    }

    /**
     * Check invoice for paying.
     *
     * @return void
    */
    public function charge($invoice)
    {
        try {
            // charge invoice
            $result = $this->doCharge($invoice->customer, [
                'id' => $invoice->uid,
                'amount' => $invoice->total(),
                'currency' => $invoice->currency->code,
                'description' => trans('messages.pay_invoice', [
                    'id' => $invoice->uid,
                ]),
            ]);

            $invoice->updateMetadata([
                'txn_id' => $result["txn_id"],
                'checkout_url' => $result["checkout_url"],
                'status_url' => $result["status_url"],
                'qrcode_url' => $result["qrcode_url"],
            ]);

            return [
                'status' => 'success',
            ];
        } catch(\Stripe\Exception\CardException $e) {
            // transaction
            $transaction = $invoice->addTransaction([
                'status' => \Acelle\Model\Transaction::STATUS_FAILED,
                'message' => trans('messages.pay_invoice', [
                    'id' => $invoice->uid,
                    'title' => $invoice->getBillingInfo()['title'],
                ]),
                'error' => $e->getError()->message,
            ]);

            return [
                'status' => 'error',
                'error' => $transaction->error,
            ];
        } catch (\Exception $e) {
            // transaction
            $transaction = $invoice->addTransaction([
                'status' => \Acelle\Model\Transaction::STATUS_FAILED,
                'message' => trans('messages.pay_invoice', [
                    'id' => $invoice->uid,
                    'title' => $invoice->getBillingInfo()['title'],
                ]),
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'error' => $transaction->error,
            ];
        }
    }
    
    /**
     * create new transaction
     *
     * @return void
     */
    public function doCharge($customer, $data=[])
    {
        $options = [
            'currency1' => $data['currency'],
            'currency2' => config('cashier.gateways.coinpayments.fields.receive_currency'),
            'amount' => $data['amount'],
            'item_name' => $data['description'],
            'item_number' => $data['id'],
            'buyer_email' => $customer->getBillableEmail(),
            'custom' => json_encode([
                'invoice_uid' => $data['id'],
            ]),
        ];
        
        $res = $this->coinPaymentsAPI->CreateSimpleTransaction($options);
        
        if ($res["error"] !== 'ok') {
            throw new \Exception($res["error"]);
        }        
        
        return $res["result"];
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

        // set gateway
        $customer->updatePaymentMethod([
            'method' => 'coinpayments',
            'user_id' => $customer->getBillableEmail(),
        ]);
        
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
        return $subscription->subscriptionTransactions()
            ->where('type', '=', SubscriptionTransaction::TYPE_SUBSCRIBE)
            ->orderBy('created_at', 'desc')
            ->first();
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
     * Get last transaction
     *
     * @return boolean
     */
    public function getLastTransactionWithInit($subscription) {
        // if has only init transaction
        if ($subscription->subscriptionTransactions()->count() < 1) {
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
    public function updateTransactionRemoteInfo($invoice)
    {
        $data = $invoice->getMetadata();
        // get remote information
        $invoice->updateMetadata($this->getTransactionRemoteInfo($data['txn_id']));
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
     * Check if paid.
     *
     * @param  Subscription  $subscription
     * @return SubscriptionParam
    */
    public function checkPay($invoice)
    {
        $this->updateTransactionRemoteInfo($invoice);

        if ($invoice->getMetadata()['status'] == 100) {
            // pay invoice 
            $invoice->pay();
        }

        if ($invoice->getMetadata()['status'] < 0) {
            // set transaction failed
            $invoice->pendingTransaction()->setFailed($invoice->getMetadata()['status_text']);
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
        // if init transaction
        if ($subscription->isPending()) {
            $transaction = $this->getInitTransaction($subscription);
            $this->updateTransactionRemoteInfo($transaction);

            // update description
            $transaction->description = $transaction->getMetadata()['remote']['status_text'];
            $transaction->save();

            if ($transaction->getMetadata()['remote']['status'] == 100) {
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

            if ($transaction->getMetadata()['remote']['status'] < 0) {
                //
                $subscription->cancelNow();
                
                //
                $transaction->setFailed();

                // add log
                $subscription->addLog(SubscriptionLog::TYPE_ERROR, [
                    'message' => 'Coinpayments transaction is cancelled / time out',
                ]);
                sleep(1);
                $subscription->addLog(SubscriptionLog::TYPE_CANCELLED_NOW, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
            }
        }

        // if renew/change plan transaction
        $transaction = $this->getLastTransaction($subscription);
        if ($transaction && $transaction->isPending()) {            
            $this->updateTransactionRemoteInfo($transaction);

            // update description
            $transaction->description = $transaction->getMetadata()['remote']['status_text'];
            $transaction->save();

            if ($transaction->getMetadata()['remote']['status'] == 100) {
                // set active
                $transaction->setSuccess();
                $this->approvePending($subscription);
                
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
            
            if ($transaction->getMetadata()['remote']['status'] < 0) {
                //
                $transaction->setFailed();

                // add log
                $subscription->addLog(SubscriptionLog::TYPE_ERROR, [
                    'message' => 'Coinpayments transaction is cancelled / time out',
                ]);

                // add error notice
                if ($transaction->type == SubscriptionTransaction::TYPE_RENEW) {
                    $subscription->error = json_encode([
                        'status' => 'warning',
                        'type' => 'renew_failed',
                        'message' => trans('cashier::messages.renew_failed_with_error', [
                            'error' => 'Coinpayments transaction is cancelled / time out',
                            'link' => \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\CoinpaymentsController@renew', [
                                'subscription_id' => $subscription->uid,
                                'return_url' => \Acelle\Cashier\Cashier::lr_action('AccountSubscriptionController@index'),
                            ]),
                        ]),
                    ]);
                } else {
                    if ($subscription->isExpiring() && $subscription->canRenewPlan()) {
                        $subscription->error = json_encode([
                            'status' => 'error',
                            'type' => 'change_plan_failed',                    
                            'message' => trans('cashier::messages.change_plan_failed_with_renew', [
                                'error' => 'Coinpayments transaction is cancelled / time out',
                                'date' => $subscription->current_period_ends_at,
                                'link' => \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\CoinpaymentsController@renew", [
                                    'subscription_id' => $subscription->uid,
                                    'return_url' => \Acelle\Cashier\Cashier::lr_action('AccountSubscriptionController@index'),
                                ]),
                            ]),
                        ]);
                    } else {
                        $subscription->error = json_encode([
                            'status' => 'error',
                            'type' => 'change_plan_failed',
                            'message' => trans('cashier::messages.change_plan_failed_with_error', [
                                'error' => 'Coinpayments transaction is cancelled / time out',
                            ]),
                        ]);
                    }
                }
                    
                $subscription->save();
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
     * Get all coins
     *
     * @return date
     */
    public function getRates()
    {
        return $this->coinPaymentsAPI->getRates();
    }

    /**
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($invoice, $returnUrl='/') {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\CoinpaymentsController@checkout", [
            'invoice_uid' => $invoice->uid,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Get connect url.
     *
     * @return string
     */
    public function getConnectUrl($returnUrl='/') {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\CoinpaymentsController@connect", [
            'return_url' => $returnUrl,
        ]);
    }
}