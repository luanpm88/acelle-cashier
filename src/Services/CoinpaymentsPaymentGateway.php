<?php
namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Carbon\Carbon;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Library\CoinPayment\CoinpaymentsAPI;

class CoinpaymentsPaymentGateway implements PaymentGatewayInterface
{
    public $coinPaymentsAPI;
    public $receive_currency;
    
    // Contruction
    public function __construct($merchantId, $publicKey, $privateKey, $ipnSecret, $receive_currency)
    {
        $this->receive_currency = $receive_currency;
        $this->coinPaymentsAPI = new CoinpaymentsAPI($privateKey, $publicKey, 'json'); // new CoinPayments($privateKey, $publicKey, $merchantId, $ipnSecret, null);

        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    public function getData($invoice) {
        return $invoice->pendingTransaction()->getMetadata();
    }

    public function updateData($invoice, $data) {
        return $invoice->pendingTransaction()->updateMetadata($data);
    }
    
    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function validate()
    {
        $info = $this->coinPaymentsAPI->getBasicInfo();
        
        if (isset($info["error"]) && $info["error"] != "ok") {
            throw new \Exception($info["error"]);
        }
    }

    public function supportsAutoBilling()
    {
        return false;
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
            $result = $this->doCharge($invoice->customer, [
                'id' => $invoice->uid,
                'amount' => $invoice->total(),
                'currency' => $invoice->currency->code,
                'description' => trans('messages.pay_invoice', [
                    'id' => $invoice->uid,
                ]),
            ]);

            $this->updateData($invoice, [
                'service' => "coinpayments",
                'txn_id' => $result["txn_id"],
                'checkout_url' => $result["checkout_url"],
                'status_url' => $result["status_url"],
                'qrcode_url' => $result["qrcode_url"],
            ]);
        } catch (\Exception $e) {
            // transaction
            $invoice->payFailed($e->getMessage());
        }
    }
    
    /**
     * create new transaction
     *
     * @return void
     */
    public function doCharge($customer, $data=[])
    {
        $options = [
            'currency1' => $data['currency'],
            'currency2' => $this->receive_currency,
            'amount' => $data['amount'],
            'item_name' => $data['description'],
            'item_number' => $data['id'],
            'buyer_email' => $customer->getBillableEmail(),
            'custom' => json_encode([
                'invoice_uid' => $data['id'],
            ]),
        ];
        
        $res = $this->coinPaymentsAPI->CreateSimpleTransaction($options);
        
        if ($res["error"] !== 'ok') {
            throw new \Exception($res["error"]);
        }
        
        return $res["result"];
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
    public function getTransactionRemoteInfo($txn_id)
    {
        $res = $this->coinPaymentsAPI->GetTxInfoSingle($txn_id, 1);
        
        if ($res["error"] !== 'ok') {
            throw new \Exception($res["Can not find remote transaction tnx_id"]);
        }
        
        return $res['result'];
    }
    
    /**
     * Get remote transaction.
     *
     * @return Boolean
     */
    public function updateTransactionRemoteInfo($invoice)
    {
        $data = $this->getData($invoice);
        // get remote information
        $this->updateData($invoice, $this->getTransactionRemoteInfo($data['txn_id']));
    }
    
    /**
     * Get remote transaction.
     *
     * @return Boolean
     */
    public function getTransaction($subscription)
    {
        $transactions = $this->getTransactions($subscription);
        
        if (empty($transactions)) {
            return null;
        }
        
        $transaction = end($transactions);
        
        if (isset($transaction['txn_id'])) {
            $transaction['remote'] = $this->getTransactionRemoteInfo($transaction['txn_id']);
        }
        
        return $transaction;
    }

    /**
     * Check if paid.
     *
     * @param  Subscription  $subscription
     * @return SubscriptionParam
    */
    public function checkPay($invoice)
    {
        $this->updateTransactionRemoteInfo($invoice);

        if ($this->getData($invoice)['status'] == 100) {
            // pay invoice
            $invoice->fulfill();
        }

        if ($this->getData($invoice)['status'] < 0) {
            // pay failed
            $invoice->payFailed($this->getData($invoice)['status_text']);
        }
    }
    
    /**
     * Convert transaction status integer to string.
     *
     * @param  Int  $subscriptionId
     * @return date
     */
    public function getTransactionStatus($number)
    {
        $statuses = [
            -2 => 'Refund / Reversal',
            -1 => 'Cancelled / Timed Out',
            0 => 'Waiting',
            1 => 'Coin Confirmed',
            2 => 'Queued',
            3 => 'PayPal Pending',
            100 => 'Complete',
        ];
        
        return $statuses[$number];
    }
    
    /**
     * Get all coins
     *
     * @return date
     */
    public function getRates()
    {
        return $this->coinPaymentsAPI->getRates();
    }

    /**
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($invoice, $returnUrl='/')
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\CoinpaymentsController@checkout", [
            'invoice_uid' => $invoice->uid,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Get connect url.
     *
     * @return string
     */
    public function getConnectUrl($returnUrl='/')
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\CoinpaymentsController@connect", [
            'return_url' => $returnUrl,
        ]);
    }
}
