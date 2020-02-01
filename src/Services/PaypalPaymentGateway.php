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
        try {
            $response = $this->client->execute(new OrdersGetRequest('ssssss'));
        } catch (\Exception $e) {
            $result = json_decode($e->getMessage(), true);
            if (isset($result['error']) && $result['error'] == 'invalid_client') {
                throw new \Exception($e->getMessage());
            }            
        }
        
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
        if ($subscription->plan->price > 0) {
            // check order ID
            $this->checkOrderID($options['orderID']);
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
    public function addTransaction($subscription, $data)
    {
        $transactions = $this->getTransactions($subscription);
        array_unshift($transactions, $data);

        $subscription->updateMetadata(['transactions' => $transactions]);
    }

    /**
     * Get transaction by subscription id.
     *
     * @return void
     */
    public function getTransaction($subscription)
    {
        $transactions = $this->getTransactions($subscription);
        if (empty($transactions)) {
            return null;
        } else {
            return $transactions[0];
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
        return true;
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
            
        } else if ($subscription->isActive()) {
            
        }
        
        // current_period_ends_at is always ends_at
        $subscription->current_period_ends_at = $subscription->ends_at;
        
        $subscription->save();
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
     * Swap subscription plan.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function checkOrderID($orderID)
    {
        // Check payment status
        $response = $this->client->execute(new OrdersGetRequest($orderID));

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
        if ($response->statusCode != 200 || $response->result->status != 'COMPLETED') {
            throw new \Exception('Something went wrong:' . json_encode($response->result));
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
        // calc result
        $result = Cashier::calcChangePlan($subscription, $newPlan);
        $amount = round($result["amount"]);
        $endsAt = $result["endsAt"];        
        $newPlan->price = $amount;

        $subscription->plan_id = $newPlan->getBillableId();
        $subscription->ends_at = $endsAt;
        $subscription->save();
        
        // save transaction
        $this->addTransaction($subscription, [
            'createdAt' => $subscription->created_at->timestamp,
            'periodEndsAt' => $subscription->ends_at->timestamp,
            'amount' => $newPlan->getBillableFormattedPrice(),
            'description' => trans('cashier::messages.paypal.change_plan_to', [
                'current_plan' => $subscription->plan->getBillableName(),
                'new_plan' => $newPlan->getBillableName(),
            ]),
            'status' => 'active'
        ]);
        
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
        // calc result
        $amount = $subscription->plan->getBillableAmount();
        $endsAt = $subscription->nextPeriod()->timestamp;

        $subscription->ends_at = $endsAt;
        $subscription->save();
        
        // save transaction
        $this->addTransaction($subscription, [
            'createdAt' => $subscription->created_at->timestamp,
            'periodEndsAt' => $subscription->ends_at->timestamp,
            'amount' => $subscription->plan->getBillableFormattedPrice(),
            'description' => trans('cashier::messages.paypal.renew_plan_desc', [
                'plan' => $subscription->plan->getBillableName(),
            ]),
            'status' => 'active'
        ]);
        
        
        
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
        
        foreach($this->getTransactions($subscription) as $transaction) {
            $invoices[] = new InvoiceParam([
                'createdAt' => $transaction['createdAt'],
                'periodEndsAt' => $transaction['periodEndsAt'],
                'amount' => $transaction['amount'],
                'description' => $transaction['description'],
                'status' => $transaction['status'],
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
        $invoices = [];

        foreach($this->getTransactions($subscription) as $transaction) {
            $invoices[] = new InvoiceParam([
                'createdAt' => $transaction['createdAt'],
                'periodEndsAt' => $transaction['periodEndsAt'],
                'amount' => $transaction['amount'],
                'description' => $transaction['description'],
                'status' => $transaction['status'],
            ]);
        }
        
        return $invoices;
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

    public function hasError($subscription) {}
    public function getErrorNotice($subscription) {}
}