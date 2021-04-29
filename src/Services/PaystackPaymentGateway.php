<?php

namespace Acelle\Cashier\Services;

use Illuminate\Support\Facades\Log;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Carbon\Carbon;
use Acelle\Cashier\Cashier;

class PaystackPaymentGateway implements PaymentGatewayInterface
{
    public $public_key;
    public $secret_key;

    /**
     * Construction
     */
    public function __construct($public_key, $secret_key)
    {
        $this->public_key = $public_key;
        $this->secret_key = $secret_key;
    }

    /**
     * Request PayPal service.
     *
     * @return void
     */
    private function request($type = 'GET', $uri, $headers = [], $body = '')
    {
        $client = new \GuzzleHttp\Client();
        $uri = 'https://api.paystack.co/' . $uri;
        $headers = array_merge([
            'Authorization' => 'Bearer ' . $this->secret_key,
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
    public function validate()
    {
        try {
            $this->request('GET', 'transaction/verify/' . 'ffffff', []);
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
        $result = $this->request('GET', 'transaction/verify/' . $ref, []);

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

        // update metadata
        $invoice->customer->updatePaymentMethodData(['last_transaction' => $result]);

        return $result;
    }

    public function getCard($customer) {
        $metadata = $customer->getPaymentMethod();

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
     * Check invoice for paying.
     *
     * @return void
    */
    public function charge($invoice)
    {
        try {
            // charge invoice
            $this->doCharge($invoice->customer, [
                'amount' => $invoice->total(),
                'currency' => $invoice->currency->code,
                'description' => trans('messages.pay_invoice', [
                    'id' => $invoice->uid,
                ]),
            ]);

            // pay invoice 
            $invoice->approve();
        } catch (\Exception $e) {
            // transaction
            $invoice->payFailed($e->getMessage());
        }
    }

    /**
     * Charge customer with subscription.
     *
     * @param  Customer                $customer
     * @return void
     */
    public function doCharge($customer, $data) {
        $card = $this->getCard($customer);

        // card not found
        if (!$card) {
            throw new \Exception('Card not found');
        }

        $result = $this->request('POST', 'transaction/charge_authorization', [], [
            'email' => $card['email'],
            'amount' => $data['amount'] * 100,
            'currency' => $data['currency'],
            'authorization_code' => $card['authorization_code'],
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
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($invoice, $returnUrl='/') {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\PaystackController@checkout", [
            'invoice_uid' => $invoice->uid,
            'return_url' => $returnUrl,
        ]);
    }

    public function supportsAutoBilling() {
        return true;
    }

    /**
     * Check currency valid.
     *
     * @return string
     */
    public function currencyValid($currency) {
        return in_array($currency, ['GHS', 'NGN', 'USD', 'ZAR']);
    }

    /**
     * Get connect url.
     *
     * @return string
     */
    public function getConnectUrl($returnUrl='/') {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\PaystackController@connect", [
            'return_url' => $returnUrl,
        ]);
    }
}