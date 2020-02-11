<?php

namespace Acelle\Cashier\Services;

use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\Cashier;
use Carbon\Carbon;
use Acelle\Cashier\Subscription;
use Acelle\Cashier\InvoiceParam;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;

class BraintreePaymentGateway implements PaymentGatewayInterface
{
    const ERROR_RECURRING_CHARGE_FAILED = 'recurring-charge-failed';

    public $environment;
    public $merchantId;
    public $publicKey;
    public $privateKey;
    public $always_ask_for_valid_card;
    
    public function __construct($environment, $merchantId, $publicKey, $privateKey, $always_ask_for_valid_card) {
        $this->environment = $environment;
        $this->merchantId = $merchantId;
        $this->publicKey = $publicKey;
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
        $this->always_ask_for_valid_card = $always_ask_for_valid_card;

        $this->serviceGateway = new \Braintree_Gateway([
            'environment' => $environment,
            'merchantId' => (isset($merchantId) ? $merchantId : 'noname'),
            'publicKey' => (isset($publicKey) ? $publicKey : 'noname'),
            'privateKey' => (isset($privateKey) ? $privateKey : 'noname'),
        ]);
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
            // @todo when is exactly started at?
            $subscription->started_at = \Carbon\Carbon::now();
        } 
        $subscription->user_id = $customer->getBillableId();
        $subscription->plan_id = $plan->getBillableId();
        $subscription->status = Subscription::STATUS_NEW;
        
        $subscription->save();

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBE, [
            'plan' => $plan->getBillableName(),
            'price' => $plan->getBillableFormattedPrice(),
        ]);
        
        return $subscription;
    }

    public function sync($subscription) {
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

        return empty($cards) ? NULL : $cards[0];
    }

    /**
     * Get the Stripe customer instance for the current user and token.
     *
     * @param  SubscriptionParam    $subscriptionParam
     * @return \Stripe\Customer
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
     * Chareg subscription.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function charge($subscription, $data)
    {
        $user = $subscription->user;
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

    public function hasPending($subscription) {}
    public function getPendingNotice($subscription) {}

    /**
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($subscription, $returnUrl='/') {
        return action("\Acelle\Cashier\Controllers\BraintreeController@checkout", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }
    
    /**
     * Get change plan url.
     *
     * @return string
     */
    public function getChangePlanUrl($subscription, $plan_id, $returnUrl='/')
    {
        return action("\Acelle\Cashier\Controllers\\BraintreeController@changePlan", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
            'plan_id' => $plan_id,
        ]);
    }

    public function getRenewUrl($subscription, $returnUrl='/') {}

    public function renew($subscription) {        
        // add transaction
        $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_AUTO_CHARGE, [
            'ends_at' => null,
            'current_period_ends_at' => $subscription->nextPeriod(),
            'status' => SubscriptionTransaction::STATUS_PENDING,
            'title' => trans('cashier::messages.transaction.recurring_charge', [
                'plan' => $subscription->plan->getBillableName(),
            ]),
            'amount' => $subscription->plan->getBillableFormattedPrice(),
        ]);

        // charge
        try {
            $this->charge($subscription, [
                'amount' => $subscription->plan->getBillableAmount(),
                'currency' => $subscription->plan->getBillableCurrency(),
                'description' => trans('cashier::messages.transaction.recurring_charge', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
            ]);

            // set active
            $transaction->setSuccess();

            // check new states from transaction
            $subscription->ends_at = $transaction->ends_at;
            // save last period
            $subscription->last_period_ends_at = $subscription->current_period_ends_at;
            // set new current period
            $subscription->current_period_ends_at = $transaction->current_period_ends_at;
            $subscription->save();

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_RENEWED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            return true;
        } catch (\Exception $e) {
            $transaction->setFailed();

            // update error message
            $transaction->description = $e->getMessage();
            $transaction->save();

            // set subscription last_error_type
            $subscription->last_error_type = BraintreePaymentGateway::ERROR_RECURRING_CHARGE_FAILED;
            $subscription->save();

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_RENEW_FAILED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get last transaction
     *
     * @return boolean
     */
    public function getLastTransaction($subscription) {
        // if has only init transaction
        if ($subscription->subscriptionTransactions()->count() <= 1) {
            return null;
        }

        return $subscription->subscriptionTransactions()->orderBy('created_at', 'desc')->first();
    }

    /**
     * Check if has failed transaction
     *
     * @return boolean
     */
    public function hasError($subscription) {
        return isset($subscription->last_error_type);
    }

    public function getErrorNotice($subscription) {
        switch ($subscription->last_error_type) {
            case StripePaymentGateway::ERROR_RECURRING_CHARGE_FAILED:
                return trans('cashier::messages.stripe.payment_error.recurring_charge_error', [
                    'url' => action('\Acelle\Cashier\Controllers\BraintreeController@fixPayment', [
                        'subscription_id' => $subscription->uid,
                    ]),
                ]);

                break;
            default:
                return trans('cashier::messages.stripe.error.something_went_wrong');
        }
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
}