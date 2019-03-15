<?php
namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\SubscriptionParam;
use Carbon\Carbon;
use Acelle\Library\CoinPayment\CoinpaymentsAPI;

class CoinpaymentsPaymentGateway implements PaymentGatewayInterface
{
    public $coinPaymentsAPI;
    
    // Contruction
    public function __construct($merchantId, $publicKey, $privateKey, $ipnSecret)
    {   
        $this->coinPaymentsAPI = new CoinpaymentsAPI($privateKey, $publicKey, 'json'); // new CoinPayments($privateKey, $publicKey, $merchantId, $ipnSecret, null);
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
            'isPending' => ($found["status"] == 0),
        ]);
        
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
     * Swap subscription plan.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function swapSubscriptionPlan($subscriptionId, $plan)
    {
    }
}