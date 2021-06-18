<?php

namespace Acelle\Cashier\Services;

use Acelle\Library\Contracts\PaymentGatewayInterface;
use Acelle\Cashier\Cashier;
use Carbon\Carbon;
use Acelle\Model\Invoice;
use Acelle\Library\TransactionVerificationResult;
use Acelle\Model\Transaction;

class BraintreePaymentGateway implements PaymentGatewayInterface
{
    public $environment;
    public $merchantId;
    public $publicKey;
    public $privateKey;
    public $serviceGateway;
    public $active=false;
    
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
        return 'Braintree';
    }

    public function getType() : string
    {
        return 'braintree';
    }

    public function getDescription() : string
    {
        return 'Receive payments from Credit / Debit card to your Braintree account';
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
                    'currency' => $invoice->currency->code,
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
    public function hasCard($user)
    {
        return $this->getCardInformation($user) !== null;
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
}
