<?php
namespace Acelle\Cashier\Services;

use Acelle\Library\Contracts\PaymentGatewayInterface;
use Carbon\Carbon;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Library\CoinPayment\CoinpaymentsAPI;
use Acelle\Model\Invoice;
use Acelle\Library\TransactionVerificationResult;
use Acelle\Model\Transaction;

class CoinpaymentsPaymentGateway implements PaymentGatewayInterface
{
    public $merchantId;
    public $publicKey;
    public $privateKey;
    public $ipnSecret;
    public $receiveCurrency;
    public $coinPaymentsAPI;
    public $active=false;
    
    // Contruction
    public function __construct($merchantId, $publicKey, $privateKey, $ipnSecret, $receiveCurrency)
    {
        $this->merchantId = $merchantId;
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
        $this->ipnSecret = $ipnSecret;
        $this->receiveCurrency = $receiveCurrency;
        $this->coinPaymentsAPI = new CoinpaymentsAPI($privateKey, $publicKey, 'json'); // new CoinPayments($privateKey, $publicKey, $merchantId, $ipnSecret, null);

        \Carbon\Carbon::setToStringFormat('jS \o\f F');

        $this->validate();
    }

    public function getName() : string
    {
        return 'Coinpayments';
    }

    public function getType() : string
    {
        return 'coinpayments';
    }

    public function getDescription() : string
    {
        return 'Receive payment from a cryptocurrency like Bitcoin, Monero, ZCash, etc.';
    }

    public function validate()
    {
        if (!$this->merchantId || !$this->publicKey || !$this->privateKey || !$this->ipnSecret || !$this->receiveCurrency) {
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
        return action("\Acelle\Cashier\Controllers\CoinpaymentsController@settings");
    }

    public function getCheckoutUrl($invoice) : string
    {
        return action("\Acelle\Cashier\Controllers\CoinpaymentsController@checkout", [
            'invoice_uid' => $invoice->uid,
        ]);
    }

    public function verify(Transaction $transaction) : TransactionVerificationResult
    {
        $invoice = $transaction->invoice;

        $this->updateTransactionRemoteInfo($invoice);

        if ($this->getData($invoice)['status'] == 100) {
            return new TransactionVerificationResult(TransactionVerificationResult::RESULT_DONE);
        } elseif ($this->getData($invoice)['status'] < 0) {
            return new TransactionVerificationResult(
                TransactionVerificationResult::RESULT_FAILED,
                'Coinpayments remote transaction is failed with error code: ' . $this->getData($invoice)['status']
            );
        } else {
            return new TransactionVerificationResult(TransactionVerificationResult::RESULT_STILL_PENDING);
        }
    }

    public function allowManualReviewingOfTransaction() : bool
    {
        return false;
    }

    public function autoCharge($invoice)
    {
        throw new \Exception('Coinpayments payment gateway does not support auto charge!');
    }

    public function getAutoBillingDataUpdateUrl($returnUrl='/') : string
    {
        throw new \Exception('
            Coinpayments gateway does not support auto charge.
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
            return false;
        }
        return $invoice->getPendingTransaction()->getMetadata();
    }

    public function updateData($invoice, $data) {
        return $invoice->getPendingTransaction()->updateMetadata($data);
    }
    
    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function test()
    {
        $info = $this->coinPaymentsAPI->getBasicInfo();
        
        if (isset($info["error"]) && $info["error"] != "ok") {
            throw new \Exception($info["error"]);
        }
    }

    /**
     * Check invoice for paying.
     *
     * @return void
    */
    public function charge($invoice)
    {
        $gateway = $this;

        $invoice->checkout($gateway, function($invoice) use ($gateway) {
            $autoBillingData = $invoice->customer->getAutoBillingData();

            try {
                // charge invoice
                $result = $gateway->doCharge($invoice->customer, [
                    'id' => $invoice->uid,
                    'amount' => $invoice->total(),
                    'currency' => $invoice->currency->code,
                    'description' => trans('messages.pay_invoice', [
                        'id' => $invoice->uid,
                    ]),
                ]);

                $gateway->updateData($invoice, [
                    'service' => "coinpayments",
                    'txn_id' => $result["txn_id"],
                    'checkout_url' => $result["checkout_url"],
                    'status_url' => $result["status_url"],
                    'qrcode_url' => $result["qrcode_url"],
                ]);

                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_STILL_PENDING);
            } catch (\Exception $e) {
                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_FAILED, $e->getMessage());
            }
        });
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
            'currency2' => $this->receiveCurrency,
            'amount' => $data['amount'],
            'item_name' => $data['description'],
            'item_number' => $data['id'],
            'buyer_email' => $customer->user->email,
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
            throw new \Exception($res["error"]);
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

    // /**
    //  * Check if paid.
    //  *
    //  * @param  Subscription  $subscription
    //  * @return SubscriptionParam
    // */
    // public function checkPay($invoice)
    // {
    //     $this->updateTransactionRemoteInfo($invoice);

    //     if ($this->getData($invoice)['status'] == 100) {
    //         // pay invoice
    //         $invoice->fulfill();
    //     }

    //     if ($this->getData($invoice)['status'] < 0) {
    //         // pay failed
    //         $invoice->payFailed($this->getData($invoice)['status_text']);
    //     }
    // }
    
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

    public function getMinimumChargeAmount($currency)
    {
        return 0;
    }
}
