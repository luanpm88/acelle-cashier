<?php

namespace Acelle\Cashier\Services;

use Illuminate\Support\Facades\Log;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\Subscription;
use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\InvoiceParam;
use Carbon\Carbon;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;

class RazorpayPaymentGateway implements PaymentGatewayInterface
{
    const ERROR_CHARGE_FAILED = 'charge-failed';

    public $key_id;
    public $key_secret;

    /**
     * Construction
     */
    public function __construct($key_id, $key_secret)
    {
        $this->key_id = $key_id;
        $this->key_secret = $key_secret;
        $this->baseUri = 'https://api.razorpay.com/v1/';
    }

    /**
     * Request PayPal service.
     *
     * @return void
     */
    private function request($type = 'GET', $uri, $headers = [], $body = '')
    {
        $client = new \GuzzleHttp\Client();
        $uri = $this->baseUri . $uri;
        $response = $client->request($type, $uri, [
            'headers' => $headers,
            'body' => is_array($body) ? json_encode($body) : $body,
            'auth' => [$this->key_id, $this->key_secret],
        ]);
        return json_decode($response->getBody(), true);
    }

    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function validate()
    {
        $this->request('GET', 'customers');
    }

    /**
     * Create a new subscription.
     *
     * @param  Customer                $customer
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
        $subscription->gateway = 'razorpay';

        $subscription->user_id = $customer->getBillableId();
        $subscription->plan_id = $plan->getBillableId();
        $subscription->status = Subscription::STATUS_NEW;
        
        // set dates and save
        $subscription->ends_at = $subscription->getPeriodEndsAt(Carbon::now());
        $subscription->current_period_ends_at = $subscription->ends_at;
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
        }

        return $subscription;
    }

    /**
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($subscription, $returnUrl='/') {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\RazorpayController@checkout", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Current rate for convert/revert Stripe price.
     *
     * @param  mixed    $price
     * @param  string    $currency
     * @return integer
     */
    public function currencyRates()
    {
        return [
            'CLP' => 1,
            'DJF' => 1,
            'JPY' => 1,
            'KMF' => 1,
            'RWF' => 1,
            'VUV' => 1,
            'XAF' => 1,
            'XOF' => 1,
            'BIF' => 1,
            'GNF' => 1,
            'KRW' => 1,
            'MGA' => 1,
            'PYG' => 1,
            'VND' => 1,
            'XPF' => 1,
        ];
    }

    /**
     * Convert price to Stripe price.
     *
     * @param  mixed    $price
     * @param  string    $currency
     * @return integer
     */
    public function convertPrice($price, $currency)
    {
        $currencyRates = $this->currencyRates();

        $rate = isset($currencyRates[$currency]) ? $currencyRates[$currency] : 100;

        return round($price * $rate);
    }

    /**
     * Get Razorpay Order.
     *
     * @param  mixed    $price
     * @param  string    $currency
     * @return integer
     */
    public function createRazorpayOrder($subscription, $plan=null)
    {
        if (!$plan) {
            $plan = $subscription->plan;
        }

        if ($plan->price == 0) {
            return false;
        }

        return $this->request('POST', 'orders', [
            "content-type" => "application/json"
        ], [
            "amount" => $this->convertPrice($plan->getBillableAmount(), $plan->getBillableCurrency()),
            "currency" => $plan->getBillableCurrency(),
            "receipt" => "rcptid_" . $subscription->uid,
            "payment_capture" => 1,
        ]);
    }
    
    /**
     * Get Razorpay Order.
     *
     * @param  mixed    $price
     * @param  string    $currency
     * @return integer
     */
    public function getRazorpayCustomer($subscription)
    {
        $data = $subscription->getMetadata();

        // get customer
        if (isset($data["customer"])) {
            $customer = $this->request('GET', 'customers/' . $data["customer"]["id"]);
        } else {
            $customer = $this->request('POST', 'customers', [
                "Content-Type" => "application/json"
            ], [
                "name" => $subscription->user->displayName(),
                "email" => $subscription->user->getBillableEmail(),
                "contact" => "",
                "fail_existing" => "0",
                "notes" => [
                    "uid" => $subscription->user->getBillableId()
                ]
            ]);
        }
        
        // save customer        
        $data['customer'] = $customer;
        $subscription->updateMetadata($data);

        return $customer;
    }
    
    /**
     * Get Razorpay Plan.
     *
     * @param  mixed    $price
     * @param  string    $currency
     * @return integer
     */
    public function createRazorpayPlan($subscription)
    {
        $plan = $this->request('POST', 'plans', [
            "Content-Type" => "application/json"
        ], [
            "period" => $subscription->plan->getBillableInterval() . 'ly',
            "interval" => $subscription->plan->getBillableIntervalCount(),
            "item" => [
                "name" => $subscription->plan->getBillableName(),
                "amount" => $this->convertPrice($subscription->plan->getBillableAmount(), $subscription->plan->getBillableCurrency()),
                "currency" => $subscription->plan->getBillableCurrency(),
                "description" => $subscription->plan->description
            ],
            "notes" => [
                "uid" => $subscription->plan->getBillableId()
            ]
        ]);

        return $plan;
    }

    /**
     * Get Razorpay Subscription.
     *
     * @param  mixed    $price
     * @param  string    $currency
     * @return integer
     */
    public function createRazorpaySubscription($subscription)
    {        
        $plan = $this->createRazorpayPlan($subscription);

        $sub = $this->request('POST', 'subscriptions', [
            "Content-Type" => "application/json"
        ], [
            "plan_id" => $plan["id"],
            "total_count" => 300,
            "quantity" => 1,
            "customer_notify" => 1,
            "start_at" => \Carbon\Carbon::now()->timestamp,
            "expire_by" => \Carbon\Carbon::now()->addYears(10)->timestamp,
            "notes" => [
                "notes_key_1" => $subscription->uid
            ]
        ]);
        var_dump($sub);
        die();
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
            $uri = 'https://secure.payu.com/pl/standard/user/oauth/authorize';
            $client = new \GuzzleHttp\Client();
            $response = $client->request('POST', $uri, [
                'headers' =>
                    [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                'body' => 'grant_type=client_credentials&client_id=' . $this->client_id . '&client_secret=' . $this->client_secret,
            ]);
            $data = json_decode($response->getBody(), true);
            $this->accessToken = $data['access_token'];
        }

        return $this->accessToken;
    }
    
    /**
     * Charge customer with subscription.
     *
     * @param  Customer                $customer
     * @param  Subscription         $subscription
     * @return void
     */
    public function charge($subscription, $data) {
        ss;
        // // get or create plan
        // $stripeCustomer = $this->getStripeCustomer($subscription->user);
        // $card = $this->getCardInformation($subscription->user);

        // if (!is_object($card)) {
        //     throw new \Exception('Can not find card information');
        // }

        // // Charge customter with current card
        // \Stripe\Charge::create([
        //     'amount' => $this->convertPrice($data['amount'], $data['currency']),
        //     'currency' => $data['currency'],
        //     'customer' => $stripeCustomer->id,
        //     'source' => $card->id,
        //     'description' => $data['description'],
        // ]);
    }

    public function sync($subscription) {
    }

    public function isSupportRecurring() {
        return false;
    }

    /**
     * Get renew url.
     *
     * @return string
     */
    public function getChangePlanUrl($subscription, $plan_id, $returnUrl='/')
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\RazorpayController@changePlan", [
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
    public function getRenewUrl($subscription, $returnUrl='/')
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\RazorpayController@renew", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Get card information from Stripe user.
     *
     * @param  Subscription    $subscription
     * @return Boolean
     */
    public function getCardInformation($user)
    {        
        // // Get new one if not exist
        // $uri = 'https://secure.payu.com/pl/standard/user/oauth/authorize';
        // $client = new \GuzzleHttp\Client();
        // $response = $client->request('POST', $uri, [
        //     'headers' =>
        //         [
        //             'Cache-Control' => 'no-cache',
        //             'Content-Type' => 'application/x-www-form-urlencoded',
        //         ],
        //     'body' => 'grant_type=trusted_merchant&client_id=' . $this->client_id . '&client_secret=' . $this->client_secret . '&email=' . $user->getBillableEmail() . '&ext_customer_id=' . $user->getBillableId(),
        // ]);
        // $data = json_decode($response->getBody(), true);
        // var_dump($data);
        // die();

        // // get or create plan
        // $stripeCustomer = $this->getStripeCustomer($user);

        // $cards = $stripeCustomer->sources->all(
        //     ['object' => 'card']
        // );

        // return empty($cards->data) ? NULL : $cards->data["0"];

        return null;
    }

    /**
     * Get the Stripe customer instance for the current user and token.
     *
     * @param  SubscriptionParam    $subscriptionParam
     * @return \Stripe\Customer
     */
    protected function getStripeCustomer($user)
    {
        // Find in gateway server
        $stripeCustomers = \Stripe\Customer::all();
        foreach ($stripeCustomers as $stripeCustomer) {
            if ($stripeCustomer->metadata->local_user_id == $user->getBillableId()) {
                return $stripeCustomer;
            }
        }

        // create if not exist
        $stripeCustomer = \Stripe\Customer::create([
            'email' => $user->getBillableEmail(),
            'metadata' => [
                'local_user_id' => $user->getBillableId(),
            ],
        ]);

        return $stripeCustomer;
    }

    /**
     * Update user card.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function billableUserUpdateCard($user, $params)
    {
        $stripeCustomer = $this->getStripeCustomer($user);

        $card = $stripeCustomer->sources->create(['source' => $params['stripeToken']]);
        $stripeCustomer->default_source = $card->id;
        $stripeCustomer->save();
    }

    /**
     * Revert price from Stripe price.
     *
     * @param  mixed    $price
     * @param  string    $currency
     * @return integer
     */
    public function revertPrice($price, $currency)
    {
        $currencyRates = $this->currencyRates();

        $rate = isset($currencyRates[$currency]) ? $currencyRates[$currency] : 100;

        return $price / $rate;
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

        if (!$subscription->hasError()) {
            // check renew pending
            if ($subscription->isExpiring() && $subscription->canRenewPlan()) {
                $subscription->error = json_encode([
                    'status' => 'warning',
                    'type' => 'renew',
                    'message' => trans('cashier::messages.renew.warning', [
                        'date' => $subscription->current_period_ends_at,
                        'link' => \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\RazorpayController@renew", [
                            'subscription_id' => $subscription->uid,
                            'return_url' => \Acelle\Cashier\Cashier::lr_action('AccountSubscriptionController@index'),
                        ]),
                    ]),
                ]);
                $subscription->save();
            }
        }
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
    
    /**
     * Get sig value.
     *
     * @return void
     */
    public function getSig($subscription)
    {
        // EURtest@test.comen145227TEST12345
        $str = '';
        // currency-code
        $str .= $subscription->plan->getBillableCurrency();
        // customer-email
        $str .= $subscription->user->getBillableEmail();
        // customer-language
        $str .= \Auth::user()->customer->getLanguageCode();
        // merchant-pos-id
        $str .= $this->client_id; 
        // recurring-payment
        $str .= 'true';
        // shop-name
        $str .= \Acelle\Model\Setting::get('site_name');    
        // store-card
        $str .= 'true';        
        // total-amount
        $str .= $subscription->plan->getBillableAmount();

        // second key
        $str .= $this->second_key;

        return hash('sha256', $str);
    }

    function verifyCharge($request) {
        $sig = hash_hmac('sha256', $request->razorpay_order_id . "|" . $request->razorpay_payment_id, $this->key_secret);
        if ($sig != $request->razorpay_signature) {
            throw new \Exception('Can not verify remote order: ' . $request->razorpay_order_id);
        }
    }
}