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
    const ERROR_CHARGE_FAILED = 'charge-failed';

    public $client_id;
    public $secret;
    public $client;
    public $environment;
    public $accessToken;
    public $baseUri;
    public $paypalProduct;
    public $path = 'integrations/paypal.json';
    
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

        // $this->initPayPalProduct();

        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    /**
     * Request PayPal service.
     *
     * @return void
     */
    private function request($type = 'GET', $uri, $headers = [], $body = '', $auth = [])
    {
        $client = new \GuzzleHttp\Client();
        $uri = $this->baseUri . $uri;
        $response = $client->request($type, $uri, [
            'headers' => $headers,
            'body' => is_array($body) ? json_encode($body) : $body,
            'auth' => $auth,
        ]);
        return json_decode($response->getBody(), true);
    }

    /**
     * Get Paypal product.
     *
     * @return void
     */
    public function initPayPalProduct()
    {
        $productId = $this->getData()['product_id'];

        if ($productId) {
            return;
        }
        
        // Get new one if not exist
        $result = $this->request('POST', '/v1/catalogs/products', [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ], [
            "name" => 'ACELLE_' . uniqid(),
            "description" => 'Acelle-PayPal',
            "type" => "SERVICE",
            "category" => "SOFTWARE",
            "image_url" => "https://previews.customer.envatousercontent.com/files/224717199/Square80.png",
            "home_url" => 'https://acellemail.com',
        ]);
        
        // update data
        $data = $this->getData();
        $data['product_id'] = $result['id'];
        $this->updateData($data);
    }

    /**
     * Get Paypal product.
     *
     * @return void
     */
    public function removePayPalProduct()
    {
        // disconnect all plans
        $data = $this->getData();
        foreach ($data['plans'] as $key => $connection) {
            // Deactive remote plan
            if ($connection['paypal_id']) {
                $plan = \Acelle\Model\Plan::findByUid($connection['uid']);

                $this->disconnectPlan($plan);
            }
        }
        $data['plans'] = [];

        // remove local product id. API can not remove PayPal product
        $data['product_id'] = '';

        // update data
        $this->updateData($data);
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
            $data = $this->request('POST', '/v1/oauth2/token', [
                    'Accept' => 'application/json',
                    'Accept-Language' => 'en_US',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'grant_type=client_credentials',
                [$this->client_id, $this->secret, 'basic']
            );

            $this->accessToken = $data['access_token'];
        }

        return $this->accessToken;
    }

    /**
     * Get Paypal plan.
     *
     * @return void
     */
    public function getPaypalPlan($plan)
    {
        $connection = $this->findPlanConnection($plan);

        // check connection exists
        if (!$connection) {
            throw new \Exception('The plan is not connected: ' . $plan->getBillableName() . ' / ' . $plan->getBillableId());
        }

        // API
        $data = $this->request('GET', '/v1/billing/plans/' . $connection['paypal_id'], [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ]);

        return $data;
    }

    /**
     * Get Paypal plan.
     *
     * @return void
     */
    public function createPaypalPlan($subscription, $plan, $setup_fee)
    {
        $paypalProduct = $this->getPaypalProduct();

        // API
        return $this->request('POST', '/v1/billing/plans', [
            'Accept' => 'application/json',
            'Prefer' => 'return=representation',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ], '{
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
        ');
    }

    /**
     * Get storage data.
     *
     * @return void
     */
    public function getData()
    {
        if (!\Storage::exists($this->path)) {
            \Storage::put($this->path, json_encode([
                'product_id' => '',
                'plans' => [],
            ]));
        }

        return json_decode(\Storage::get($this->path), true);
    }

    /**
     * Get storage data.
     *
     * @return void
     */
    public function updateData($data)
    {
        \Storage::put($this->path, json_encode($data));
    }

    /**
     * Get Paypal product.
     *
     * @return void
     */
    public function getPaypalProduct()
    {
        // API
        return $this->request('GET', '/v1/catalogs/products/' . $this->getData()['product_id'], [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ]);
    }
    
    /**
     * Create paypal subscription.
     *
     * @return void
     */
    public function createPaypalSubscription($subscription, $subscriptionID)
    {
        $paypalPlan = $this->getPaypalPlan($subscription->plan);

        // API
        $data = $this->request('POST', '/v1/billing/subscriptions', [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'PayPal-Request-Id' => $subscriptionID,
            'Prefer' => 'return=representation',
            'Content-Type' => 'application/json',
        ], '{
            "plan_id": "' . $paypalPlan['id'] . '",
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
              }
            }
        }');

        // update subscription
        $metadata = $subscription->getMetadata();
        $metadata['subscription'] = $data;
        $metadata['subscriptionID'] = $subscriptionID;
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

        // suspend api
        $this->request('POST', '/v1/billing/subscriptions/' . $subscription->getMetadata()['subscriptionID'] . '/suspend', [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
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

        // activate via api
        $this->request('POST', '/v1/billing/subscriptions/' . $subscription->getMetadata()['subscriptionID'] . '/activate', [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
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
        
        // cancel via api
        $this->request('POST', '/v1/billing/subscriptions/' . $subscription->getMetadata()['subscriptionID'] . '/cancel', [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
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
     * Gateway check subscription method.
     *
     * @return void
     */
    public function checkSubscription($subscription)
    {
        // free plan and local renew
        if ($subscription->plan->getBillableAmount() == 0 && $subscription->isExpiring()) {
            $this->renew($subscription);
            return;
        }

        // no remote subscription
        if (empty($subscription->getMetadata())) {
            return false;
        }

        // update from service
        $this->syncPaypalSubscription($subscription);

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
     * Gateway check subscription method.
     *
     * @return void
     */
    public function checkLastTransaction($transaction)
    {
        // free plan and local renew
        if (!isset($transaction) || !$transaction->isPending()) {
            return;
        }

        $subscription = $transaction->subscription;

        // APPROVAL_PENDING. The subscription is created but not yet approved by the buyer.
        // APPROVED. The buyer has approved the subscription.
        // ACTIVE. The subscription is active.
        // SUSPENDED. The subscription is suspended.
        // CANCELLED. The subscription is cancelled.
        // EXPIRED. The subscription is expired.

        // Get new one if not exist
        try {
            $paypalSubscription = $this->getPaypalSubscriptionById($transaction->getMetadata()['subscriptionID']);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = json_decode($e->getResponse()->getBody()->getContents(), true);
            
            if ($response['name'] == 'RESOURCE_NOT_FOUND') {
                // set failed
                $transaction->setFailed();
                
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_ERROR, [
                    'message' => trans('cashier::messages.paypal_subscription.remote_sub_not_found', [
                        'id' => $transaction->getMetadata()['subscriptionID']
                    ]),
                ]);
            } else {
                throw new \Exception($e->getMessage());
            }

            return false;
        }

        switch ($paypalSubscription['status']) {
            case 'APPROVAL_PENDING':
                break;

            case 'APPROVED':
                break;

            case 'ACTIVE':
            case 'SUSPENDED':
                // log
                if ($transaction->type == SubscriptionTransaction::TYPE_PLAN_CHANGE) {
                    $transaction->setSuccess();

                    // check new states
                    $subscription->ends_at = null;

                    // period date update
                    if ($subscription->current_period_ends_at != $transaction->current_period_ends_at) {
                        // save last period
                        $subscription->last_period_ends_at = $subscription->current_period_ends_at;
                        // set new current period
                        $subscription->current_period_ends_at = $transaction->current_period_ends_at;
                    }

                    // check new plan
                    $transactionData = $transaction->getMetadata();
                    $oldPlan = $subscription->plan;
                    if (isset($transactionData['plan_id'])) {
                        $subscription->plan_id = $transactionData['plan_id'];
                    }

                    // cancel old subscription
                    $this->cancelPaypalSubscription($subscription);
                    // add new subscription data
                    $data = $subscription->getMetadata();
                    $data['subscriptionID'] = $transactionData['subscriptionID'];
                    $data['subscription'] = $transactionData['paypal_subscription'];
                    $subscription->updateMetadata($data);

                    // save all
                    $subscription->save();

                    $subscription = Subscription::find($subscription->id);
                    // add log
                    $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGED, [
                        'old_plan' => $oldPlan->getBillableName(),
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                    ]);

                    // remove last_error
                    $subscription->error = null;
                    $subscription->save();
                }

                break;

            case 'CANCELLED':
                $transaction->setFailed();
                break;

            case 'EXPIRED':
                $transaction->setFailed();
                break;

            default:
                throw new \Exception('Status is invalid: ' . $paypalSubscription['status']);
        }
    }

    /**
     * Gateway check subscription method.
     *
     * @return void
     */
    public function updateSubscriptionTransactions($subscription)
    {
        if (!isset($subscription->getMetadata()['subscriptionID'])) {
            return false;
        }

        $subscriptionID = $subscription->getMetadata()['subscriptionID'];

        // Get new one if not exist
        $data = $this->request('GET', '/v1/billing/subscriptions/' . $subscriptionID . '/transactions?start_time=' . Carbon::now()->subMonth(1)->toISOString() . '&end_time=' . Carbon::now()->toISOString(), [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ]);
        
        if ($data && isset($data["transactions"])) {
            foreach ($data["transactions"] as $transaction) {
                $exist = $subscription->subscriptionTransactions()
                    ->where('description','=', 'RemoteID: ' . $transaction['id'])
                    ->first();

                if (!is_object($exist)) {
                    // subscription transaction
                    $exist = $subscription->addTransaction(SubscriptionTransaction::TYPE_AUTO_CHARGE, [
                        'ends_at' => null,
                        'current_period_ends_at' => null,
                        'status' => $transaction['status'],
                        'title' => trans('cashier::messages.transaction.paypal_subscription.remote_transaction'),
                        'amount' => $transaction['amount_with_breakdown']['gross_amount']['value'],
                        'description' => 'RemoteID: ' . $transaction['id'],
                        'created_at' => Carbon::parse($transaction['time']),
                    ]);
                    $exist->created_at = Carbon::parse($transaction['time']);
                    $exist->save();
                } else {
                    $exist->status =  $transaction['status'];
                    $exist->amount =  $transaction['amount_with_breakdown']['gross_amount']['value'];
                    $exist->save();
                }
            }
        }
    }

    /**
     * Gateway check subscription method.
     *
     * @return void
     */
    public function getPayPalPlanById($id)
    {
        return $this->request('GET', '/v1/billing/plans/' . $id, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ]);
    }

    /**
     * Gateway check subscription method.
     *
     * @return void
     */
    public function checkPlans()
    {
        $data = $this->getData();
        foreach ($data['plans'] as $key => $connection) {
            $plan = \Acelle\Model\Plan::findByUid($connection['uid']);

            try {
                // get PayPal Plan
                $data = $this->getPayPalPlanById($connection['paypal_id']);

                // update price
                if($data['billing_cycles'][0]['pricing_scheme']['fixed_price']['value']) {
                    $plan->price = $data['billing_cycles'][0]['pricing_scheme']['fixed_price']['value'];
                    $plan->save();
                }

            } catch (\GuzzleHttp\Exception\ClientException $e) {
                $response = json_decode($e->getResponse()->getBody()->getContents(), true);
                
                if ($response['name'] == 'RESOURCE_NOT_FOUND') {
                    // disconnect
                    $this->removePlanConnection($plan);
                    
                    // disable local plan
                    $plan->disable();
                } else {
                    throw new \Exception($e->getMessage());
                }
            }
        }
    }

    /**
     * Gateway check method.
     *
     * @return void
     */
    public function check($subscription)
    {
        // check expired
        if ($subscription->isExpired()) {
            $subscription->cancelNow();

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_EXPIRED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);
        }

        // check remote status
        if (!$subscription->isEnded()) {
            // check subscription status
            $this->checkSubscription($subscription);

            // check subscription status
            $this->updateSubscriptionTransactions($subscription);
        }

        // Check last pending transaction
        if ($subscription->isActive()) {
            $this->checkLastTransaction($this->getLastTransaction($subscription));
        }
    }

    /**
     * Gateway check all.
     *
     * @return void
     */
    public function checkAll()
    {
        $this->checkPlans();
    }

    /**
     * Get Paypal subscription.
     *
     * @return void
     */
    public function getPaypalSubscriptionById($subscriptionID)
    {        
        return $this->request('GET', '/v1/billing/subscriptions/' . $subscriptionID, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ]);
    }

    /**
     * Get Paypal subscription.
     *
     * @return void
     */
    public function syncPaypalSubscription($subscription)
    {
        $subscriptionID = $subscription->getMetadata()['subscriptionID'];

        // Get new one if not exist
        try {
            $data = $this->getPaypalSubscriptionById($subscriptionID);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = json_decode($e->getResponse()->getBody()->getContents(), true);
            
            if ($response['name'] == 'RESOURCE_NOT_FOUND') {
                // cancel subscription
                $subscription->cancel();
                
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_ERROR, [
                    'message' => trans('cashier::messages.paypal_subscription.remote_sub_not_found', [
                        'id' => $subscriptionID
                    ]),
                ]);
                sleep(1);
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_CANCELLED_NOW, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
            } else {
                throw new \Exception($e->getMessage());
            }

            return false;
        }

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
            $response = $this->getAccessToken();
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());           
        }

        if (!$this->getData()['product_id']) {
            throw new \Exception('Can not find product_id!');
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
        } 
        // @todo when is exactly started at?
        $subscription->started_at = \Carbon\Carbon::now();

        // set gateway
        $subscription->gateway = 'paypal_subscription';
        
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
            
            sleep(1);
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBED, [
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
        throw new \Exception(trans('cashier::messages.paypal_subscription.not_support_set_active'));
    }

    /**
     * Allow admin approve pending transaction.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function approvePending($subscription)
    {
        throw new \Exception(trans('cashier::messages.paypal_subscription.not_support_approve'));
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
    
    // /**
    //  * Check for notice.
    //  *
    //  * @param  Subscription  $subscription
    //  * @return date
    //  */
    // public function hasPending($subscription)
    // {
    //     $transaction = $this->getLastTransaction($subscription);
    //     return $transaction && $transaction->isPending() && !in_array($transaction->type, [
    //         SubscriptionTransaction::TYPE_SUBSCRIBE,
    //     ]);
    // }
    
    // /**
    //  * Get notice message.
    //  *
    //  * @param  Subscription  $subscription
    //  * @return date
    //  */
    // public function getPendingNotice($subscription)
    // {
    //     $transaction = $this->getLastTransaction($subscription);
        
    //     return trans('cashier::messages.paypal_subscription.has_transaction_pending', [
    //         'description' => $transaction->title,
    //         'amount' => $transaction->amount,
    //         'url' => $this->getTransactionPendingUrl($subscription, \Acelle\Cashier\Cashier::lr_action('AccountSubscriptionController@index')),
    //     ]);
    // }
    
    /**
     * Get renew url.
     *
     * @return string
     */
    public function getRenewUrl($subscription, $returnUrl='/')
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\PaypalSubscriptionController@renew", [
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
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\PaypalSubscriptionController@checkout", [
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
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\PaypalSubscriptionController@changePlan", [
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
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\PaypalSubscriptionController@pending", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Get renew url.
     *
     * @return string
     */
    public function getTransactionPendingUrl($subscription, $returnUrl='/')
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\PaypalSubscriptionController@transactionPending", [
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
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\PaypalSubscriptionController@ChangePlanpending", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Get last transaction
     *
     * @return boolean
     */
    public function getLastTransaction($subscription) {
        return $subscription->subscriptionTransactions()
            ->where('type', '<>', SubscriptionLog::TYPE_SUBSCRIBE)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    // /**
    //  * Check if has failed transaction
    //  *
    //  * @return boolean
    //  */
    // public function hasError($subscription) {
    //     $transaction = $this->getLastTransaction($subscription);

    //     return isset($subscription->last_error_type) && $transaction->isFailed();
    // }

    // public function getErrorNotice($subscription) {
    //     return trans('cashier::messages.paypal_subscription.error.something_went_wrong');
    // }

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
        $this->cancelPaypalSubscription($subscription);

        $subscription->cancelNow();

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
        $amount = $newAmount - $remainAmount;
        
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

    /**
     * Find plan connection.
     *
     * @return void
     */
    public function findPlanConnection($plan)
    {
        if ($plan->getBillableAmount() == 0) {
            return [
                'uid' => $plan->uid,
                'paypal_id' => null,
            ];
        }

        $data = $this->getData();

        foreach ($data['plans'] as $key => $item) {
            if ($item['uid'] == $plan->getBillableId()) {
                return $item;
            }
        }

        return false;
    }

    /**
     * Find plan connection.
     *
     * @return void
     */
    public function removePlanConnection($plan)
    {
        $data = $this->getData();
        // $key = array_search($plan->getBillableId(), array_column($data['plans'], 'uid'));
        
        // if ($key !== false) {
        //     unset($data['plans'][$key]);
        // }

        foreach ($data['plans'] as $key => $item) {
            if ($item['uid'] == $plan->getBillableId()) {
                unset($data['plans'][$key]);
            }
        }

        $this->updateData($data);
    }
    
    /**
     * Connect plan.
     *
     * @return void
     */
    public function connectPlan($plan)
    {
        $paypalProduct = $this->getPaypalProduct();

        // Get new one if not exist
        $data = $this->request('POST', '/v1/billing/plans', [
            'Accept' => 'application/json',
            'Prefer' => 'return=representation',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ], [
            "product_id" => $paypalProduct['id'],
            "name" => $plan->getBillableName(),
            "description" => $plan->description,
            "billing_cycles" => [
                [
                    "frequency" => [
                        "interval_unit" => strtoupper($plan->getBillableInterval()),
                        "interval_count" => $plan->getBillableIntervalCount(),
                    ],
                    "tenure_type" => "REGULAR",
                    "sequence" => 1,
                    "total_cycles" => 12,
                    "pricing_scheme" => [                        
                        "fixed_price" => [
                            "value" => $plan->getBillableAmount(),
                            "currency_code" => $plan->getBillableCurrency(),
                        ]
                    ]
                ]
            ],
            "payment_preferences" => [
                "auto_bill_outstanding" => true,
                "setup_fee" => [
                    "value" => "0",
                    "currency_code" => $plan->getBillableCurrency(),
                ],
                "setup_fee_failure_action" => "CONTINUE",
                "payment_failure_threshold" => 3,
            ],
            "taxes" => [
              "percentage" => "0",
              "inclusive" => false
            ]
        ]);

        // connection
        $connection = [
            'uid' => $plan->getBillableId(),
            'paypal_id' => $data['id'],
        ];

        // save
        $data = $this->getData();
        $data['plans'][] = $connection;
        $this->updateData($data);
    }

    /**
     * Connect plan.
     *
     * @return void
     */
    public function disconnectPlan($plan)
    {
        $connection = $this->findPlanConnection($plan);

        if (!$connection) {
            return false;
        }

        // remove connection local
        $this->removePlanConnection($plan);

        // Deactive remote plan
        $this->deactivatePayPalPlan($connection['paypal_id']);
    }

    /**
     * Connect plan.
     *
     * @return void
     */
    public function deactivatePayPalPlan($planId)
    {
        if (!$planId) {
            return false;
        }

        try {
            // Deactive remote plan
            $this->request('POST', '/v1/billing/plans/' . $planId . '/deactivate', [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $response = json_decode($e->getResponse()->getBody()->getContents(), true);
            
            if ($response['name'] == 'RESOURCE_NOT_FOUND') {
                // already deactivated
            } else {
                throw new \Exception($e->getMessage());
            }
        }
    }

    /**
     * Change plan.
     *
     * @return array
     */
    public function changePlan($subscription, $plan, $subscriptionID)
    {
        $paypalPlan = $this->getPaypalPlan($plan);

        // API
        return $this->request('POST', '/v1/billing/subscriptions', [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
            'PayPal-Request-Id' => $subscriptionID,
            'Prefer' => 'return=representation',
            'Content-Type' => 'application/json',
        ], '{
            "plan_id": "' . $paypalPlan['id'] . '",
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
              }
            }
        }');
    }
    
    /**
     * Change plan.
     *
     * @return array
     */
    public function syncPlan($plan, $oldPlan)
    {
        $connection = $this->findPlanConnection($plan);

        // return if not connected
        if (!$connection || !$connection['paypal_id']) {
            return false;
        }

        // find changed fields
        $amountChanged = ($plan->getBillableAmount() != $oldPlan->getBillableAmount());
        $currencyChanged = ($plan->getBillableCurrency() != $oldPlan->getBillableCurrency());
        $intervalChanged = ($plan->getBillableInterval() != $oldPlan->getBillableInterval());
        $intervalCountChanged = ($plan->getBillableIntervalCount() != $oldPlan->getBillableIntervalCount());

        if ($intervalChanged || $intervalCountChanged) {
            throw new \Exception('The connected plan can not be updated: can not change billing cycle of remote plan');
        }

        if ($amountChanged || $currencyChanged) {
            // API
            $result = $this->request('POST', '/v1/billing/plans/' . $connection['paypal_id'] . '/update-pricing-schemes', [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
            ], '{
                "pricing_schemes": [{
                  "billing_cycle_sequence": 1,
                  "pricing_scheme": {
                    "fixed_price": {
                        "value": "' . $plan->getBillableAmount() . '",
                        "currency_code": "' . $plan->getBillableCurrency() . '"
                      }
                    }
                  }
                ]
            }');
        }
    }
}