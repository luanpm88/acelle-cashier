<?php

namespace Acelle\Cashier\Services;

use Illuminate\Support\Facades\Log;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\Subscription;
use Acelle\Cashier\SubscriptionParam;
use Acelle\Cashier\InvoiceParam;
use Carbon\Carbon;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;

class PaystackPaymentGateway implements PaymentGatewayInterface
{
    const ERROR_CHARGE_FAILED = 'charge-failed';

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
        } 
        // @todo when is exactly started at?
        $subscription->started_at = \Carbon\Carbon::now();

        // set gateway
        $subscription->gateway = 'paystack';

        $subscription->user_id = $customer->getBillableId();
        $subscription->plan_id = $plan->getBillableId();
        $subscription->status = Subscription::STATUS_NEW;
        
        // set dates and save
        $subscription->ends_at = $subscription->getPeriodEndsAt(Carbon::now());
        $subscription->current_period_ends_at = $subscription->ends_at;
        $subscription->save();
        
        // // If plan is free: enable subscription & update transaction
        // if ($plan->getBillableAmount() == 0) {
        //     // subscription transaction
        //     $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
        //         'ends_at' => $subscription->ends_at,
        //         'current_period_ends_at' => $subscription->current_period_ends_at,
        //         'status' => SubscriptionTransaction::STATUS_SUCCESS,
        //         'title' => trans('cashier::messages.transaction.subscribed_to_plan', [
        //             'plan' => $subscription->plan->getBillableName(),
        //         ]),
        //         'amount' => $subscription->plan->getBillableFormattedPrice(),
        //     ]);
            
        //     // set active
        //     $subscription->setActive();

        //     // add log
        //     $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBED, [
        //         'plan' => $plan->getBillableName(),
        //         'price' => $plan->getBillableFormattedPrice(),
        //     ]);
        // }

        return $subscription;
    }

    /**
     * Verify payment transaction.
     *
     * @return void
     */
    public function verifyPayment($subscription, $ref)
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
        $subscription->updateMetadata(['last_transaction' => $result]);

        return $result;
    }

    /**
     * Gateway check method.
     *
     * @return void
     */
    public function check($subscription)
    {
        // check expired
        if ($subscription->isExpired()) {
            $subscription->cancelNow();

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_EXPIRED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);
        }
        
        // check from service: recurring/transaction
        if ($subscription->isRecurring() && $subscription->isExpiring()) {
            $this->renew($subscription);
        }
    }

    public function getCard($subscription) {
        $metadata = $subscription->getMetadata();

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
     * @param  Subscription         $subscription
     * @return void
     */
    public function charge($subscription, $data) {
        $card = $this->getCard($subscription);

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
            $subscription->error = json_encode([
                'status' => 'error',
                'type' => 'renew',
                'message' => trans('cashier::messages.renew.card_error', [
                    'date' => $subscription->current_period_ends_at,
                    'error' => $e->getMessage(),
                    'link' => \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\PaystackController@renew", [
                        'subscription_id' => $subscription->uid,
                        'return_url' => \Acelle\Cashier\Cashier::lr_action('AccountSubscriptionController@index'),
                    ]),
                ]),
            ]);
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
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($subscription, $returnUrl='/') {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\PaystackController@checkout", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }

    public function sync($subscription) {
    }

    public function isSupportRecurring() {
        return true;
    }

    /**
     * Get renew url.
     *
     * @return string
     */
    public function getChangePlanUrl($subscription, $plan_id, $returnUrl='/')
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\PaystackController@changePlan", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
            'plan_id' => $plan_id,
        ]);
    }

    /**
     * Get renew url.
     *
     * @return string
     */
    public function getRenewUrl($subscription, $returnUrl='/')
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\PaystackController@renew", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
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

    /**
     * Check currency valid.
     *
     * @return string
     */
    public function currencyValid($currency) {
        return in_array($currency, ['GHS', 'NGN', 'USD']);
    }
}