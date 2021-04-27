<?php

namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\Cashier;
use Carbon\Carbon;

class BraintreePaymentGateway implements PaymentGatewayInterface
{
    public $environment;
    public $merchantId;
    public $publicKey;
    public $privateKey;
    public $always_ask_for_valid_card;
    
    public function __construct($environment, $merchantId, $publicKey, $privateKey, $always_ask_for_valid_card) {
        $this->environment = $environment;
        $this->merchantId = $merchantId;
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
        $this->always_ask_for_valid_card = $always_ask_for_valid_card;

        $this->serviceGateway = new \Braintree_Gateway([
            'environment' => $environment,
            'merchantId' => (isset($merchantId) ? $merchantId : 'noname'),
            'publicKey' => (isset($publicKey) ? $publicKey : 'noname'),
            'privateKey' => (isset($privateKey) ? $privateKey : 'noname'),
        ]);

        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function validate()
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
     public function hasCard($user) {
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
            \Braintree_CustomerSearch::email()->is($user->getBillableEmail())
        ]);
        
        if ($braintreeCustomers->maximumCount() == 0) {
            // create if not exist
            $result = $this->serviceGateway->customer()->create([
                'email' => $user->getBillableEmail(),
            ]);
            
            if ($result->success) {
                $braintreeCustomer = $result->customer;
            } else {
                foreach($result->errors->deepAll() AS $error) {
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
            $invoice->pay();

            return [
                'status' => 'success',
            ];
        } catch (\Exception $e) {
            // pay failed
            $invoice->payFailed($e->getMessage());

            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Chareg subscription.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function doCharge($user, $data)
    {
        $braintreeUser = $this->getBraintreeCustomer($user);
        $card = $this->getCardInformation($user);

        if (!is_object($card)) {
            throw new \Exception('Can not find card information');
        }
        
        $result = $this->serviceGateway->transaction()->sale([
            'amount' => $data['amount'],
            'paymentMethodToken' => $card->token,
        ]);
          
        if ($result->success) {
        } else {
            foreach($result->errors->deepAll() AS $error) {
                throw new \Exception($error->code . ": " . $error->message . "\n");
            }
        }
    }

    /**
     * Service does not support auto recurring.
     *
     * @return boolean
     */
    public function isSupportRecurring() {
        return true;
    }

    /**
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($invoice, $returnUrl='/') {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\BraintreeController@checkout", [
            'invoice_uid' => $invoice->uid,
            'return_url' => $returnUrl,
        ]);
    }

    public function supportsAutoBilling() {
        return true;
    }

    /**
     * Get connect url.
     *
     * @return string
     */
    public function getConnectUrl($returnUrl='/') {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\BraintreeController@connect", [
            'return_url' => $returnUrl,
        ]);
    }
}