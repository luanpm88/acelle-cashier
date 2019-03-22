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
        return true;
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
        $transactions = $this->coinPaymentsAPI->GetTxIds(["limit" => 100]);
        
        $found = null;
        foreach($transactions["result"] as $transaction) {
            $result = $this->coinPaymentsAPI->GetTxInfoSingle($transaction, 1)["result"];
            $id = $result["checkout"]["item_number"];
            if ($subscriptionId == $id) {
                $found = $result;
                break;
            }
        }
        
        if (!isset($found)) {
            throw new \Exception('Subscription can not be found');
        }
        
        $subscriptionParam = new SubscriptionParam([
            'currentPeriodEnd' => \Carbon\Carbon::createFromTimestamp($found["time_created"])->addMonth(1)->timestamp,
            'createdAt' => $found["time_created"],
        ]);
        
        if ($found["status"] == 0) {
            $subscriptionParam->status = Subscription::STATUS_PENDING;
        }
        
        var_dump($found);
        
        if ($found["status"] > 0) {
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
     * Change subscription plan.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function changeSubscriptionPlan($subscriptionId, $plan)
    {
        $currentSubscription = $user->subscription();
        $currentSubscription->markAsCancelled();
        
        $subscription = $user->createSubscription($plan, $this);        
        $subscription->charge($this);
        
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
        $transactions = $this->coinPaymentsAPI->GetTxIds(["limit" => 100]);
        
        $invoices = [];
        foreach($transactions["result"] as $transaction) {
            $result = $this->coinPaymentsAPI->GetTxInfoSingle($transaction, 1)["result"];
            $id = $result["checkout"]["item_number"];
            if ($subscriptionId == $id) {
                $invoices[] = new InvoiceParam([
                    'time' => $result['time_created'],
                    'amount' => $result['amount'] . " " . $result['coin'],
                    'description' => $result['status_text'],
                    'status' => $result['status']
                ]);
            }
        }
        
        return $invoices;
    }
}