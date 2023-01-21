<?php

namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\Cashier;
use Carbon\Carbon;
use Acelle\Model\Invoice;
use Acelle\Cashier\Library\TransactionVerificationResult;
use Acelle\Model\Transaction;

class BraintreePaymentGateway implements PaymentGatewayInterface
{
    public $environment;
    public $merchantId;
    public $publicKey;
    public $privateKey;
    public $serviceGateway;
    public $active=false;

    public const TYPE = 'braintree';

    public function __construct($environment, $merchantId, $publicKey, $privateKey)
    {
        $this->environment = $environment;
        $this->merchantId = $merchantId;
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;

        $this->validate();

        if ($this->isActive()) {
            $this->serviceGateway = new \Braintree_Gateway([
                'environment' => $environment,
                'merchantId' => (isset($merchantId) ? $merchantId : 'noname'),
                'publicKey' => (isset($publicKey) ? $publicKey : 'noname'),
                'privateKey' => (isset($privateKey) ? $privateKey : 'noname'),
            ]);
        }

        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    public function getName() : string
    {
        return trans('cashier::messages.braintree');
    }

    public function getType() : string
    {
        return self::TYPE;
    }

    public function getDescription() : string
    {
        return trans('cashier::messages.braintree.description');
    }

    public function getShortDescription() : string
    {
        return trans('cashier::messages.braintree.short_description');
    }

    public function getSettingsUrl() : string
    {
        return action("\Acelle\Cashier\Controllers\BraintreeController@settings");
    }

    public function validate()
    {
        if (!$this->environment || !$this->merchantId || !$this->privateKey || !$this->publicKey) {
            $this->active = false;
        } else {
            $this->active = true;
        }
    }

    public function isActive() : bool
    {
        return $this->active;
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

        $invoice->checkout($this, function($invoice) use ($gateway) {
            $autoBillingData = $invoice->customer->getAutoBillingData();

            try {
                // charge invoice
                $gateway->doCharge([
                    'paymentMethodToken' => $autoBillingData->getData()['paymentMethodToken'],
                    'amount' => $invoice->total(),
                    'currency' => $invoice->getCurrencyCode(),
                    'description' => trans('messages.pay_invoice', [
                        'id' => $invoice->uid,
                    ]),
                ]);

                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_DONE);
            } catch (\Exception $e) {
                return new TransactionVerificationResult(TransactionVerificationResult::RESULT_FAILED, $e->getMessage());
            }
        });
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

    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function test()
    {
        try {
            $clientToken = $this->serviceGateway->clientToken()->generate([
                "customerId" => '123'
            ]);
        } catch (\Braintree_Exception_Authentication $e) {
            throw new \Exception('Braintree Exception Authentication Failed');
        } catch (\Exception $e) {
            // do nothing
        }
    }

    /**
     * Check if subscription has future payment pending.
     *
     * @param  Subscription    $subscription
     * @return Boolean
     */
    public function getCardInformation($user)
    {
        // get or create plan
        $braintreeCustomer = $this->getBraintreeCustomer($user);

        $cards = $braintreeCustomer->paymentMethods;

        return empty($cards) ? null : $cards[0];
    }

    /**
     * Get user has card.
     *
     * @return string
     */
    public function hasCard($customer)
    {
        $autoBillingData = $customer->getAutoBillingData();
        $card = $this->getCardInformation($customer);
        return $card !== null &&
            $autoBillingData != null &&
            isset($autoBillingData->getData()['paymentMethodToken']) && 
            $card->token == $autoBillingData->getData()['paymentMethodToken'];
    }

    /**
     * Get the braintree customer instance for the current user and token.
     *
     * @param  SubscriptionParam    $subscriptionParam
     * @return \Braintree\Customer
     */
    protected function getBraintreeCustomer($user)
    {
        // Find in gateway server
        $braintreeCustomers = $this->serviceGateway->customer()->search([
            \Braintree_CustomerSearch::email()->is($user->user->email)
        ]);
        
        if ($braintreeCustomers->maximumCount() == 0) {
            // create if not exist
            $result = $this->serviceGateway->customer()->create([
                'email' => $user->user->email,
            ]);
            
            if ($result->success) {
                $braintreeCustomer = $result->customer;
            } else {
                foreach ($result->errors->deepAll() as $error) {
                    throw new \Exception($error->code . ": " . $error->message . "\n");
                }
            }
        } else {
            $braintreeCustomer = $braintreeCustomers->firstItem();
        }


        return $braintreeCustomer;
    }

    /**
     * Update customer card.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function updateCard($user, $nonce)
    {
        $braintreeCustomer = $this->getBraintreeCustomer($user);
        
        // update card
        $updateResult = $this->serviceGateway->customer()->update(
            $braintreeCustomer->id,
            [
              'paymentMethodNonce' => $nonce
            ]
        );
    }

    /**
     * Chareg subscription.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function doCharge($data)
    {        
        $result = $this->serviceGateway->transaction()->sale([
            'amount' => $data['amount'],
            'paymentMethodToken' => $data['paymentMethodToken'],
        ]);
          
        if ($result->success) {
        } else {
            foreach ($result->errors->deepAll() as $error) {
                throw new \Exception($error->code . ": " . $error->message . "\n");
            }
        }
    }

    /**
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($invoice) : string
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\BraintreeController@checkout", [
            'invoice_uid' => $invoice->uid,
        ]);
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
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\BraintreeController@autoBillingDataUpdate", [
            'return_url' => $returnUrl,
        ]);
    }

    public function getMinimumChargeAmount($currency)
    {
        $minimums = [
            'USD' => 1,
            'AED' => 3.6725,
            'AFN' => 83.4725,
            'ALL' => 103.3481,
            'AMD' => 485.3464,
            'ANG' => 1.7900,
            'AOA' => 612.9974,
            'ARS' => 98.4735,
            'AUD' => 1.3796,
            'AWG' => 1.7900,
            'AZN' => 1.6998,
            'BAM' => 1.6649,
            'BBD' => 2.0000,
            'BDT' => 85.3858,
            'BGN' => 1.6651,
            'BHD' => 0.3760,
            'BIF' => 1982.9041,
            'BMD' => 1.0000,
            'BND' => 1.3514,
            'BOB' => 6.8904,
            'BRL' => 5.2733,
            'BSD' => 1.0000,
            'BTN' => 73.7975,
            'BWP' => 11.1475,
            'BYN' => 2.5034,
            'BZD' => 2.0000,
            'CAD' => 1.2765,
            'CDF' => 1987.5231,
            'CHF' => 0.9239,
            'CLP' => 785.7866,
            'CNY' => 6.4668,
            'COP' => 3859.7283,
            'CRC' => 624.1671,
            'CUC' => 1.0000,
            'CUP' => 25.0000,
            'CVE' => 93.8619,
            'CZK' => 21.6995,
            'DJF' => 177.7210,
            'DKK' => 6.3506,
            'DOP' => 56.5525,
            'DZD' => 136.9245,
            'EGP' => 15.7072,
            'ERN' => 15.0000,
            'ETB' => 46.3212,
            'EUR' => 0.8513,
            'FJD' => 2.0940,
            'FKP' => 0.7323,
            'FOK' => 6.3506,
            'GBP' => 0.7323,
            'GEL' => 3.1113,
            'GGP' => 0.7323,
            'GHS' => 6.0262,
            'GIP' => 0.7323,
            'GMD' => 52.2645,
            'GNF' => 9780.1878,
            'GTQ' => 7.7282,
            'GYD' => 209.2905,
            'HKD' => 7.7852,
            'HNL' => 24.1296,
            'HRK' => 6.4137,
            'HTG' => 98.0105,
            'HUF' => 303.1662,
            'IDR' => 14085.9204,
            'ILS' => 3.2160,
            'IMP' => 0.7323,
            'INR' => 73.7978,
            'IQD' => 1461.2411,
            'IRR' => 41996.1574,
            'ISK' => 129.7222,
            'JMD' => 148.3767,
            'JOD' => 0.7090,
            'JPY' => 109.5762,
            'KES' => 110.3373,
            'KGS' => 84.8945,
            'KHR' => 4081.8139,
            'KID' => 1.3796,
            'KMF' => 418.7821,
            'KRW' => 1184.0350,
            'KWD' => 0.2996,
            'KYD' => 0.8333,
            'KZT' => 426.1139,
            'LAK' => 9790.4165,
            'LBP' => 1507.5000,
            'LKR' => 199.6232,
            'LRD' => 171.5704,
            'LSL' => 14.7847,
            'LYD' => 4.5327,
            'MAD' => 8.9851,
            'MDL' => 17.6888,
            'MGA' => 3962.2004,
            'MKD' => 52.5671,
            'MMK' => 1808.1743,
            'MNT' => 2854.9103,
            'MOP' => 8.0188,
            'MRU' => 36.3830,
            'MUR' => 42.4430,
            'MVR' => 15.4479,
            'MWK' => 815.0672,
            'MXN' => 20.0914,
            'MYR' => 4.1886,
            'MZN' => 64.0469,
            'NAD' => 14.7847,
            'NGN' => 422.8020,
            'NIO' => 35.1636,
            'NOK' => 8.6516,
            'NPR' => 118.0760,
            'NZD' => 1.4266,
            'OMR' => 0.3845,
            'PAB' => 1.0000,
            'PEN' => 4.1130,
            'PGK' => 3.5134,
            'PHP' => 50.2898,
            'PKR' => 169.0723,
            'PLN' => 3.9513,
            'PYG' => 6963.5984,
            'QAR' => 3.6400,
            'RON' => 4.2183,
            'RSD' => 100.2724,
            'RUB' => 72.8424,
            'RWF' => 1014.9786,
            'SAR' => 3.7500,
            'SBD' => 7.9856,
            'SCR' => 13.4854,
            'SDG' => 439.0218,
            'SEK' => 8.6767,
            'SGD' => 1.3515,
            'SHP' => 0.7323,
            'SLL' => 10480.6256,
            'SOS' => 578.9298,
            'SRD' => 21.4389,
            'SSP' => 177.7608,
            'STN' => 20.8554,
            'SYP' => 1677.1325,
            'SZL' => 14.7847,
            'THB' => 33.4474,
            'TJS' => 11.3180,
            'TMT' => 3.4990,
            'TND' => 2.7864,
            'TOP' => 2.2634,
            'TRY' => 8.6511,
            'TTD' => 6.7915,
            'TVD' => 1.3796,
            'TWD' => 27.7633,
            'TZS' => 2316.0274,
            'UAH' => 26.6353,
            'UGX' => 3534.8255,
            'UYU' => 42.7712,
            'UZS' => 10732.1945,
            'VES' => 4031955.9299,
            'VND' => 22745.9670,
            'VUV' => 112.1713,
            'WST' => 2.5800,
            'XAF' => 558.3761,
            'XCD' => 2.7000,
            'XDR' => 0.7045,
            'XOF' => 558.3761,
            'XPF' => 101.5800,
            'YER' => 250.6594,
            'ZAR' => 14.7822,
            'ZMW' => 16.4667,
        ];
        
        if (!isset($minimums[$currency])) {
            // 
            throw new \Exception('Currency is not supported by Braintree: ' . $currency);
        }

        return $minimums[$currency];
    }
}
