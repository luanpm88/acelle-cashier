<?php

namespace Acelle\Cashier\Services;

use Acelle\Cashier\Cashier;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\Subscription;
use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\InvoiceParam;
use Carbon\Carbon;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;

class PaypalSubscriptionPaymentGateway implements PaymentGatewayInterface
{
    public $client_id;
    public $secret;
    public $client;
    public $environment;
    public $accessToken;
    public $baseUri;
    
    public function __construct($environment, $client_id, $secret)
    {
        $this->environment = $environment;
        $this->client_id = $client_id;
        $this->secret = $secret;

        if ($this->environment == 'sandbox') {
            $this->baseUri = 'https://api.sandbox.paypal.com';
        } else {
            $this->baseUri = 'https://api.paypal.com';
        }
    }

    /**
     * Get access token.
     *
     * @return void
     */
    public function getAccessToken()
    {
        if (!isset($this->accessToken)) {
            // Get new one if not exist
            $uri = $this->baseUri . '/v1/oauth2/token';
            $client = new \GuzzleHttp\Client();
            $response = $client->request('POST', $uri, [
                'headers' =>
                    [
                        'Accept' => 'application/json',
                        'Accept-Language' => 'en_US',
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                'body' => 'grant_type=client_credentials',
                'auth' => [$this->client_id, $this->secret, 'basic']
            ]);
            $data = json_decode($response->getBody(), true);
            $this->accessToken = $data['access_token'];
        }

        return $this->accessToken;
    }

    /**
     * Get Paypal plan.
     *
     * @return void
     */
    public function getPaypalPlan($subscription)
    {
        $paypalProduct = $this->getPaypalProduct($subscription);

        // Get new one if not exist
        $uri = $this->baseUri . '/v1/billing/plans';
        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $uri, [
            'headers' =>
                [
                    'Accept' => 'application/json',
                    'Prefer' => 'return=representation',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                ],
            'body' => '
            {
                "product_id": "' . $paypalProduct['id'] . '",
                "name": "' . $subscription->plan->getBillableName() . '",
                "description": "' . $subscription->plan->description . '",
                "billing_cycles": [
                  {
                    "frequency": {
                        "interval_unit": "' . strtoupper($subscription->plan->getBillableInterval()) . '",
                        "interval_count": ' . $subscription->plan->getBillableIntervalCount() . '
                    },
                    "tenure_type": "REGULAR",
                    "sequence": 1,
                    "total_cycles": 12,
                    "pricing_scheme": {
                        "fixed_price": {
                            "value": "' . $subscription->plan->getBillableAmount() . '",
                            "currency_code": "' . $subscription->plan->getBillableCurrency() . '"
                        }
                    }
                  }
                ],
                "payment_preferences": {
                  "auto_bill_outstanding": true,
                  "setup_fee": {
                    "value": "0",
                    "currency_code": "' . $subscription->plan->getBillableCurrency() . '"
                  },
                  "setup_fee_failure_action": "CONTINUE",
                  "payment_failure_threshold": 3
                },
                "taxes": {
                  "percentage": "0",
                  "inclusive": false
                }
              }
            ',
        ]);
        $data = json_decode($response->getBody(), true);

        // update plan metadata
        $metadata = $subscription->getMetadata();
        $metadata['plan'] = $data;
        $subscription->updateMetadata($metadata);

        return $data;
    }

    /**
     * Get Paypal plan.
     *
     * @return void
     */
    public function createPaypalPlan($subscription, $plan, $setup_fee)
    {
        $paypalProduct = $this->getPaypalProduct($subscription);

        // Get new one if not exist
        $uri = $this->baseUri . '/v1/billing/plans';
        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $uri, [
            'headers' =>
                [
                    'Accept' => 'application/json',
                    'Prefer' => 'return=representation',
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                ],
            'body' => '
            {
                "product_id": "' . $paypalProduct['id'] . '",
                "name": "' . $plan->getBillableName() . '",
                "description": "' . $plan->description . '",
                "billing_cycles": [
                  {
                    "frequency": {
                        "interval_unit": "' . strtoupper($plan->getBillableInterval()) . '",
                        "interval_count": ' . $plan->getBillableIntervalCount() . '
                    },
                    "tenure_type": "REGULAR",
                    "sequence": 1,
                    "total_cycles": 12,
                    "pricing_scheme": {
                        "fixed_price": {
                            "value": "' . $plan->getBillableAmount() . '",
                            "currency_code": "' . $plan->getBillableCurrency() . '"
                        }
                    }
                  }
                ],
                "payment_preferences": {
                  "auto_bill_outstanding": true,
                  "setup_fee": {
                    "value": "' .$setup_fee. '",
                    "currency_code": "' . $plan->getBillableCurrency() . '"
                  },
                  "setup_fee_failure_action": "CONTINUE",
                  "payment_failure_threshold": 3
                },
                "taxes": {
                  "percentage": "0",
                  "inclusive": false
                }
              }
            ',
        ]);
        $data = json_decode($response->getBody(), true);

        return $data;
    }

    /**
     * Get Paypal product.
     *
     * @return void
     */
    public function getPaypalProduct($subscription)
    {
        // Get new one if not exist
        $uri = $this->baseUri . '/v1/catalogs/products';
        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $uri, [
            'headers' =>
                [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                ],
            'body' => json_encode([
                "name" => $subscription->plan->getBillableName(),
                "description" => $subscription->plan->description,
                "type" => "SERVICE",
                "category" => "SOFTWARE",
                "image_url" => "https://previews.customer.envatousercontent.com/files/224717199/Square80.png",
                "home_url" => url('/'),
            ]),
        ]);
        $data = json_decode($response->getBody(), true);

        // update product metadata
        $metadata = $subscription->getMetadata();
        $metadata['product'] = $data;
        $subscription->updateMetadata($metadata);

        return $data;
    }
    
    /**
     * Create paypal subscription.
     *
     * @return void
     */
    public function createPaypalSubscription($subscription, $requestId)
    {
        // Get new one if not exist
        $uri = $this->baseUri . '/v1/billing/subscriptions';
        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $uri, [
            'headers' =>
                [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                    'PayPal-Request-Id' => $requestId,
                    'Prefer' => 'return=representation',
                    'Content-Type' => 'application/json',
                ],
            'body' => '{
                "plan_id": "' . $subscription->getMetadata()['plan']['id'] . '",
                "start_time": "' . Carbon::now()->addDay(1)->toAtomString() . '",
                "subscriber": {
                  "name": {
                    "given_name": "' . $subscription->user->displayName() . '",
                    "surname": ""
                  },
                  "email_address": "' . $subscription->user->getBillableEmail() . '"
                },
                "application_context": {
                  "brand_name": "Acelle/Cashier",
                  "locale": "en-US",
                  "shipping_preference": "SET_PROVIDED_ADDRESS",
                  "user_action": "SUBSCRIBE_NOW",
                  "payment_method": {
                    "payer_selected": "PAYPAL",
                    "payee_preferred": "IMMEDIATE_PAYMENT_REQUIRED"
                  },
                  "return_url": "' . action('\Acelle\Cashier\Controllers\PaypalSubscriptionController@checkout', $subscription->uid) . '",
                  "cancel_url": "' . action('\Acelle\Cashier\Controllers\PaypalSubscriptionController@checkout', $subscription->uid) . '"
                }
            }',
        ]);
        $data = json_decode($response->getBody(), true);

        // update subscription
        $metadata = $subscription->getMetadata();
        $metadata['subscription'] = $data;
        $metadata['requestId'] = $requestId;
        $subscription->updateMetadata($metadata);

        return $data;
    }

    /**
     * Suspend paypal subscription.
     *
     * @return void
     */
    public function suspendPaypalSubscription($subscription)
    {
        if (empty($subscription->getMetadata())) {
            return false;
        }

        // Get new one if not exist
        $uri = $this->baseUri . '/v1/billing/subscriptions/' . $subscription->getMetadata()['requestId'] . '/suspend';
        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $uri, [
            'headers' =>
                [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                ],
        ]);
    }

    /**
     * Activate paypal subscription.
     *
     * @return void
     */
    public function activatePaypalSubscription($subscription)
    {
        if (empty($subscription->getMetadata())) {
            return false;
        }

        // Get new one if not exist
        $uri = $this->baseUri . '/v1/billing/subscriptions/' . $subscription->getMetadata()['requestId'] . '/activate';
        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $uri, [
            'headers' =>
                [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                ],
        ]);
    }

    /**
     * Activate paypal subscription.
     *
     * @return void
     */
    public function cancelPaypalSubscription($subscription)
    {
        if (empty($subscription->getMetadata())) {
            return false;
        }
        
        // Get new one if not exist
        $uri = $this->baseUri . '/v1/billing/subscriptions/' . $subscription->getMetadata()['requestId'] . '/cancel';
        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $uri, [
            'headers' =>
                [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                ],
        ]);
    }

    public function renew($subscription) {
        // save last period
        $subscription->last_period_ends_at = $subscription->current_period_ends_at;
        // set new current period
        $subscription->current_period_ends_at = $subscription->nextPeriod();
        $subscription->save();

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_RENEWED, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
    }

    /**
     * Gateway check method.
     *
     * @return void
     */
    public function check($subscription)
    {
        // free plan and local renew
        if ($subscription->plan->getBillableAmount() == 0 && $subscription->isExpiring($this)) {
            $this->renew($subscription);
            return;
        }

        // update from service
        if (!$this->syncPaypalSubscription($subscription)) {
            return false;
        }

        // APPROVAL_PENDING. The subscription is created but not yet approved by the buyer.
        // APPROVED. The buyer has approved the subscription.
        // ACTIVE. The subscription is active.
        // SUSPENDED. The subscription is suspended.
        // CANCELLED. The subscription is cancelled.
        // EXPIRED. The subscription is expired.

        $paypalSubscription = $subscription->getMetadata()['subscription'];

        switch ($paypalSubscription['status']) {
            case 'APPROVAL_PENDING':
                break;

            case 'APPROVED':
                break;

            case 'ACTIVE':
                if ($subscription->isPending()) {
                    // transaction
                    $transaction = $this->getInitTransaction($subscription);
                    $transaction->setSuccess();

                    // start subscription
                    $subscription->ends_at = null;
                    $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(\Carbon\Carbon::now());
                    $subscription->status = Subscription::STATUS_ACTIVE;
                    $subscription->started_at = \Carbon\Carbon::parse($paypalSubscription['start_time']);
                    $subscription->save();

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

                if ($subscription->isActive()) {
                    // update next_billing_time aka current_period_ends_at
                    if (\Carbon\Carbon::parse($paypalSubscription['billing_info']['next_billing_time']) > \Carbon\Carbon::now()) {
                        $subscription->current_period_ends_at = \Carbon\Carbon::parse($paypalSubscription['billing_info']['next_billing_time']);
                    }

                    // resume if was cancelled
                    $subscription->ends_at = null;

                    $subscription->save();
                }

                break;

            case 'SUSPENDED':
                $subscription->cancel();

                // add log
                $subscription->addLog(SubscriptionLog::TYPE_CANCELLED, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
                break;

            case 'CANCELLED':
                $subscription->cancelNow();

                // add log
                $subscription->addLog(SubscriptionLog::TYPE_CANCELLED_NOW, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
                break;

            case 'EXPIRED':
                $subscription->cancelNow();

                // add log
                $subscription->addLog(SubscriptionLog::TYPE_EXPIRED, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
                break;

            default:
                throw new \Exception('Status is invalid: ' . $paypalSubscription['status']);
        }
    }

    /**
     * Get Paypal subscription.
     *
     * @return void
     */
    public function syncPaypalSubscription($subscription)
    {
        if (empty($subscription->getMetadata())) {
            return false;
        }

        // Get new one if not exist
        $uri = $this->baseUri . '/v1/billing/subscriptions/' . $subscription->getMetadata()['requestId'];
        $client = new \GuzzleHttp\Client();
        $response = $client->request('GET', $uri, [
            'headers' =>
            [
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Content-Type' => 'application/json',
            ],
        ]);
        $data = json_decode($response->getBody(), true);

        // update subscription
        $metadata = $subscription->getMetadata();
        $metadata['subscription'] = $data;
        $subscription->updateMetadata($metadata);

        return $data;
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
        if ($customer->subscription) {
            $subscription = $customer->subscription;
        } else {
            $subscription = new Subscription();
            $subscription->user_id = $customer->getBillableId();
            // @todo when is exactly started at?
            $subscription->started_at = \Carbon\Carbon::now();
        }
        $subscription->user_id = $customer->getBillableId();
        $subscription->plan_id = $plan->getBillableId();
        $subscription->status = Subscription::STATUS_NEW;
        
        // set dates and save
        $subscription->ends_at = null;
        $subscription->current_period_ends_at = $subscription->getPeriodEndsAt(Carbon::now());
        $subscription->save();
        
        // If plan is free: enable subscription & update transaction
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

            // add log
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
     * Create a new subscriptionParam.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function charge($subscription, $options=[])
    {
        // check order ID
        $this->checkOrderID($options['orderID']);
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
        throw new \Exception(trans('cashier::messages.paypal.not_support_set_active'));
    }
    
    /**
     * Retrieve subscription param.
     *
     * @param  Subscription  $subscription
     * @return SubscriptionParam
     */
    public function sync($subscription)
    {
    }
    
    /**
     * Check if support recurring.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function isSupportRecurring()
    {
        return true;
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

        // if failed
        if ($response->statusCode != 200 || $response->result->status != 'COMPLETED') {
            throw new \Exception('Something went wrong:' . json_encode($response->result));
        }
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
        return action("\Acelle\Cashier\Controllers\\PaypalSubscriptionController@renew", [
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
        return action("\Acelle\Cashier\Controllers\PaypalSubscriptionController@checkout", [
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
        return action("\Acelle\Cashier\Controllers\\PaypalSubscriptionController@changePlan", [
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
        return action("\Acelle\Cashier\Controllers\\PaypalSubscriptionController@pending", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Get renew url.
     *
     * @return string
     */
    public function getChangePlanPendingUrl($subscription, $returnUrl='/')
    {
        return action("\Acelle\Cashier\Controllers\\PaypalSubscriptionController@ChangePlanpending", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }

    public function hasError($subscription) {}
    public function getErrorNotice($subscription) {}

    /**
     * Cancel subscription.
     *
     * @return string
     */
    public function cancel($subscription) {
        $subscription->cancel();

        // remote suspend
        $this->suspendPaypalSubscription($subscription);

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

        $this->cancelPaypalSubscription($subscription);

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_CANCELLED_NOW, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
    }

    /**
     * Cancel now subscription.
     *
     * @return string
     */
    public function setExpired($subscription) {
        $subscription->cancelNow();

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_EXPIRED, [
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

        // activate paypal subscription
        $this->activatePaypalSubscription($subscription);

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_RESUMED, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
    }

    /**
     * Get init transaction
     *
     * @return boolean
     */
    public function getInitTransaction($subscription) {
        return $subscription->subscriptionTransactions()
            ->where('type', '=', SubscriptionTransaction::TYPE_SUBSCRIBE)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Calculate change plan amount.
     *
     * @return array
     */
    public function calcChangePlan($subscription, $plan)
    {
        if (($subscription->plan->getBillableInterval() != $plan->getBillableInterval()) ||
            ($subscription->plan->getBillableIntervalCount() != $plan->getBillableIntervalCount()) ||
            ($subscription->plan->getBillableCurrency() != $plan->getBillableCurrency())
        ) {
            throw new \Exception(trans('cashier::messages.can_not_change_to_diff_currency_period_plan'));
        }

        // amout per day of current plan
        $currentAmount = $subscription->plan->getBillableAmount();

        // calculate total period days
        $periodDays = $subscription->current_period_ends_at->diffInDays($subscription->periodStartAt());

        // Remain days of current period
        $remainDays = $subscription->current_period_ends_at->diffInDays(\Carbon\Carbon::now());

        // Amount per day of current plan/period
        $currentPerDayAmount = ($currentAmount/$periodDays);

        // remain amount cusomter not use        
        $remainAmount = $currentPerDayAmount*$remainDays;

        // new amount of new plan
        $newAmount = $plan->getBillableAmount();

        // discount not use amount
        $amount = $newAmount + $remainAmount;
        
        // if amount < 0
        if ($amount <= 0) {
            $amount = 0;
        }

        return [
            'new_amount' => round($newAmount, 2),
            'remain_amount' => round($remainAmount, 2),
            'amount' => round($amount, 2),
            'ends_at' => $subscription->getPeriodEndsAt($subscription->current_period_ends_at),
        ];
    }

    /**
     * Check if use remote subscription.
     *
     * @return void
     */
    public function useRemoteSubscription()
    {
        return true;
    }
}