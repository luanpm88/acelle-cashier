<?php

namespace Acelle\Cashier\Services;

use Illuminate\Support\Facades\Log;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Carbon\Carbon;
use Acelle\Cashier\Cashier;

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
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($invoice, $returnUrl='/') {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\RazorpayController@checkout", [
            'invoice_uid' => $invoice->uid,
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
    public function createRazorpayOrder($invoice, $plan=null)
    {
        return $this->request('POST', 'orders', [
            "content-type" => "application/json"
        ], [
            "amount" => $this->convertPrice($invoice->total(), $invoice->currency->code),
            "currency" => $invoice->currency->code,
            "receipt" => "rcptid_" . $invoice->uid,
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
    public function getRazorpayCustomer($invoice)
    {
        $data = $invoice->getMetadata();

        // get customer
        if (isset($data["customer"])) {
            $customer = $this->request('GET', 'customers/' . $data["customer"]["id"]);
        } else {
            $customer = $this->request('POST', 'customers', [
                "Content-Type" => "application/json"
            ], [
                "name" => $invoice->customer->displayName(),
                "email" => $invoice->customer->getBillableEmail(),
                "contact" => "",
                "fail_existing" => "0",
                "notes" => [
                    "uid" => $invoice->customer->getBillableId()
                ]
            ]);
        }
        
        // save customer        
        $data['customer'] = $customer;
        $invoice->updateMetadata($data);

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

    // public function sync($subscription) {
    // }

    public function supportsAutoBilling() {
        return false;
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

    /**
     * Get connect url.
     *
     * @return string
     */
    public function getConnectUrl($returnUrl='/') {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\RazorpayController@connect", [
            'return_url' => $returnUrl,
        ]);
    }
}