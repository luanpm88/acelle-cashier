<?php

namespace Acelle\Cashier\Services;

use Illuminate\Support\Facades\Log;
use Acelle\Library\Contracts\PaymentGatewayInterface;
use Carbon\Carbon;
use Acelle\Cashier\Cashier;
use Acelle\Library\AutoBillingData;
use Acelle\Model\Invoice;
use Acelle\Library\TransactionVerificationResult;
use Acelle\Model\Transaction;

class PaystackPaymentGateway implements PaymentGatewayInterface
{
    public $publicKey;
    public $secretKey;
    public $active=false;

    /**
     * Construction
     */
    public function __construct($publicKey, $secretKey)
    {
        $this->publicKey = $publicKey;
        $this->secretKey = $secretKey;

        $this->validate();
    }

    public function getName() : string
    {
        return 'Paystack';
    }

    public function getType() : string
    {
        return 'paystack';
    }

    public function getDescription() : string
    {
        return 'Receive payments from Credit / Debit card to your Paystack account';
    }

    public function getSettingsUrl() : string
    {
        return action("\Acelle\Cashier\Controllers\PaystackController@settings");
    }

    public function validate()
    {
        if (!$this->publicKey || !$this->secretKey) {
            $this->active = false;
        } else {
            $this->active = true;
        }
    }

    public function isActive() : bool
    {
        return $this->active;
    }

    public function getEnvironment()
    {
        return $this->environment;
    }

    public function getMerchantId()
    {
        return $this->merchantId;
    }

    public function getPublicKey()
    {
        return $this->publicKey;
    }

    public function getPrivateKey()
    {
        return $this->privateKey;
    }

    public function supportsAutoBilling() : bool
    {
        return true;
    }

    /**
     * Get connect url.
     *
     * @return string
     */
    public function getAutoBillingDataUpdateUrl($returnUrl='/') : string
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\PaystackController@autoBillingDataUpdate", [
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($invoice) : string
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\PaystackController@checkout", [
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

    /**
     * Check invoice for paying.
     *
     * @return void
    */
    public function autoCharge($invoice)
    {
        $gateway = $this;

        $invoice->checkout($gateway, function($invoice) use ($gateway) {
            $card = $gateway->getCard($invoice->customer);

            try {
                // charge invoice
                $gateway->doCharge([
                    'amount' => $invoice->total(),
                    'currency' => $invoice->currency->code,
                    'description' => trans('messages.pay_invoice', [
                        'id' => $invoice->uid,
                    ]),
                    'email' => $card['email'],
                    'authorization_code' => $card['authorization_code'],
                ]);

                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_DONE);
            } catch (\Exception $e) {
                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_FAILED, $e->getMessage());
            }
        });
    }

    /**
     * Request PayPal service.
     *
     * @return void
     */
    private function request($uri, $type = 'GET', $headers = [], $body = '')
    {
        $client = new \GuzzleHttp\Client();
        $uri = 'https://api.paystack.co/' . $uri;
        $headers = array_merge([
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Content-Type' => 'application/json',
        ], $headers);
        $response = $client->request($type, $uri, [
            'headers' => $headers,
            'body' => is_array($body) ? json_encode($body) : $body,
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
        try {
            $this->request('transaction/verify/' . 'ffffff', 'GET', []);
        } catch (\Exception $ex) {
            if (strpos($ex->getMessage(), 'Invalid key') !== false) {
                throw new \Exception('Invalid key');
            }
        }
    }

    /**
     * Verify payment transaction.
     *
     * @return void
     */
    public function verifyPayment($invoice, $ref)
    {
        $result = $this->request('transaction/verify/' . $ref, 'GET', []);

        // transaction failed
        if (!$result['status']) {
            throw new \Exception($result['message']);
        }

        // data failed
        if (!isset($result['data'])) {
            throw new \Exception('No data return from service');
        }

        // data failed
        if ($result['data']['status'] != 'success') {
            throw new \Exception($result['data']['message']);
        }

        // update auto billing data
        $autoBillingData = new AutoBillingData($this, [
            'last_transaction' => $result,
        ]);
        $invoice->customer->setAutoBillingData($autoBillingData);

        return $result;
    }

    public function getCard($customer)
    {
        $autoBillingData = $customer->getAutoBillingData();
        $metadata = $autoBillingData->getData();

        // check last transaction
        if (!isset($metadata['last_transaction']) ||
            !isset($metadata['last_transaction']['data']) ||
            !isset($metadata['last_transaction']['data']['authorization']) ||
            !isset($metadata['last_transaction']['data']['authorization']['authorization_code']) ||
            !isset($metadata['last_transaction']['data']['customer']) ||
            !isset($metadata['last_transaction']['data']['customer']['email'])
        ) {
            return false;
        } else {
            return [
                'authorization_code' => $metadata['last_transaction']['data']['authorization']['authorization_code'],
                'email' => $metadata['last_transaction']['data']['customer']['email'],
                'last4' => $metadata['last_transaction']['data']['authorization']['last4'],
            ];
        }
    }

    /**
     * Charge customer with subscription.
     *
     * @param  Customer                $customer
     * @return void
     */
    public function doCharge($data)
    {
        $result = $this->request('transaction/charge_authorization', 'POST', [], [
            'email' => $data['email'],
            'amount' => $data['amount'] * 100,
            'currency' => $data['currency'],
            'authorization_code' => $data['authorization_code'],
        ]);

        // transaction failed
        if (!$result['status']) {
            throw new \Exception($result['message']);
        }

        // data failed
        if (!isset($result['data'])) {
            throw new \Exception('No data return from service');
        }

        // data failed
        if ($result['data']['status'] != 'success') {
            throw new \Exception($result['data']['message']);
        }

        return $result;
    }

    /**
     * Check currency valid.
     *
     * @return string
     */
    public function currencyValid($currency)
    {
        return in_array($currency, ['GHS', 'NGN', 'USD', 'ZAR']);
    }
}
