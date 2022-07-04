<?php

namespace Acelle\Cashier\Services;

use Illuminate\Support\Facades\Log;
use Acelle\Library\Contracts\PaymentGatewayInterface;
use Carbon\Carbon;
use Acelle\Cashier\Cashier;
use Acelle\Model\Invoice;
use Acelle\Library\TransactionVerificationResult;
use Acelle\Model\Transaction;

class RazorpayPaymentGateway implements PaymentGatewayInterface
{
    public $keyId;
    public $keySecret;
    public $active=false;

    /**
     * Construction
     */
    public function __construct($keyId, $keySecret)
    {
        $this->keyId = $keyId;
        $this->keySecret = $keySecret;
        $this->baseUri = 'https://api.razorpay.com/v1/';

        $this->validate();
    }

    public function getName() : string
    {
        return 'Razorpay';
    }

    public function getType() : string
    {
        return 'razorpay';
    }

    public function getDescription() : string
    {
        return trans('cashier::messages.razorpay.description');
    }

    public function getShortDescription() : string
    {
        return trans('cashier::messages.razorpay.short_description');
    }

    public function validate()
    {
        if (!$this->keyId || !$this->keySecret) {
            $this->active = false;
        } else {
            $this->active = true;
        }
        
    }

    public function isActive() : bool
    {
        return $this->active;
    }

    public function getSettingsUrl() : string
    {
        return action("\Acelle\Cashier\Controllers\RazorpayController@settings");
    }

    public function getCheckoutUrl($invoice) : string
    {
        return action("\Acelle\Cashier\Controllers\RazorpayController@checkout", [
            'invoice_uid' => $invoice->uid,
        ]);
    }

    public function verify(Transaction $transaction) : TransactionVerificationResult
    {
        return new TransactionVerificationResult(TransactionVerificationResult::RESULT_VERIFICATION_NOT_NEEDED);
    }

    public function allowManualReviewingOfTransaction() : bool
    {
        return false;
    }

    public function autoCharge($invoice)
    {
        throw new \Exception('Razorpay payment gateway does not support auto charge!');
    }

    public function getAutoBillingDataUpdateUrl($returnUrl='/') : string
    {
        throw new \Exception('
            Razorpay gateway does not support auto charge.
            Therefor method getAutoBillingDataUpdateUrl is not supported.
            Something wrong in your design flow!
            Check if a gateway supports auto billing by calling $gateway->supportsAutoBilling().
        ');
    }

    public function supportsAutoBilling() : bool
    {
        return false;
    }

    public function getData($invoice) {
        if (!$invoice->getPendingTransaction()) {
            return [];
        }
        return $invoice->getPendingTransaction()->getMetadata();
    }

    public function updateData($invoice, $data) {
        return $invoice->getPendingTransaction()->updateMetadata($data);
    }

    /**
     * Request PayPal service.
     *
     * @return void
     */
    private function request($uri, $type = 'GET', $headers = [], $body = '')
    {
        $client = new \GuzzleHttp\Client();
        $uri = $this->baseUri . $uri;
        $response = $client->request($type, $uri, [
            'headers' => $headers,
            'body' => is_array($body) ? json_encode($body) : $body,
            'auth' => [$this->keyId, $this->keySecret],
        ]);
        return json_decode($response->getBody(), true);
    }

    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function test()
    {
        $this->request('customers', 'GET');
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
        return $this->request('orders', 'POST', [
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
        $data = $this->getData($invoice);

        // get customer
        if (isset($data["customer"])) {
            $customer = $this->request('customers/' . $data["customer"]["id"], 'GET');
        } else {
            $customer = $this->request('customers', 'POST', [
                "Content-Type" => "application/json"
            ], [
                "name" => $invoice->customer->user->displayName(),
                "email" => $invoice->customer->user->email,
                "contact" => "",
                "fail_existing" => "0",
                "notes" => [
                    "uid" => $invoice->customer->uid
                ]
            ]);
        }
        
        // // save customer
        // $data['customer'] = $customer;
        // $this->updateData($invoice, $data);

        return $customer;
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
     * Check invoice for paying.
     *
     * @return void
    */
    public function charge($invoice, $request)
    {
        $gateway = $this;

        $invoice->checkout($gateway, function($invoice) use ($gateway, $request) {
            try {
                // charge invoice
                $gateway->verifyCharge($request);

                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_DONE);
            } catch (\Exception $e) {
                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_FAILED, $e->getMessage());
            }
        });
    }

    public function verifyCharge($request)
    {
        $sig = hash_hmac('sha256', $request->razorpay_order_id . "|" . $request->razorpay_payment_id, $this->keySecret);
        if ($sig != $request->razorpay_signature) {
            throw new \Exception('Can not verify remote order: ' . $request->razorpay_order_id);
        }
    }

    public function getMinimumChargeAmount($currency)
    {
        return 0;
    }
}
