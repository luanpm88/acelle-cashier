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

class PayuPaymentGateway implements PaymentGatewayInterface
{
    const ERROR_RECURRING_CHARGE_FAILED = 'recurring-charge-failed';

    public $client_id;
    public $client_secret;
    public $second_key;
    public $always_ask_for_valid_card;
    public $accessToken;

    /**
     * Construction
     */
    public function __construct($client_id, $client_secret, $second_key, $always_ask_for_valid_card)
    {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        $this->second_key = $second_key;
        $this->always_ask_for_valid_card = $always_ask_for_valid_card;
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
     * Check if service is valid.
     *
     * @return void
     */
    public function validate()
    {
        $this->getAccessToken();
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
    
    /**
     * Charge customer with subscription.
     *
     * @param  Customer                $customer
     * @param  Subscription         $subscription
     * @return void
     */
    public function charge($subscription, $data) {
        ss;
        // // get or create plan
        // $stripeCustomer = $this->getStripeCustomer($subscription->user);
        // $card = $this->getCardInformation($subscription->user);

        // if (!is_object($card)) {
        //     throw new \Exception('Can not find card information');
        // }

        // // Charge customter with current card
        // \Stripe\Charge::create([
        //     'amount' => $this->convertPrice($data['amount'], $data['currency']),
        //     'currency' => $data['currency'],
        //     'customer' => $stripeCustomer->id,
        //     'source' => $card->id,
        //     'description' => $data['description'],
        // ]);
    }

    public function sync($subscription) {
    }

    public function isSupportRecurring() {
        return true;
    }

    /**
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($subscription, $returnUrl='/') {
        return action("\Acelle\Cashier\Controllers\PayuController@checkout", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Get change plan url.
     *
     * @return string
     */
    public function getChangePlanUrl($subscription, $plan_id, $returnUrl='/') {
        return action("\Acelle\Cashier\Controllers\PayuController@changePlan", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
            'plan_id' => $plan_id,
        ]);
    }

    public function getRenewUrl($subscription, $returnUrl='/') {
        return false;
    }

    /**
     * Get card information from Stripe user.
     *
     * @param  Subscription    $subscription
     * @return Boolean
     */
    public function getCardInformation($user)
    {        
        // // Get new one if not exist
        // $uri = 'https://secure.payu.com/pl/standard/user/oauth/authorize';
        // $client = new \GuzzleHttp\Client();
        // $response = $client->request('POST', $uri, [
        //     'headers' =>
        //         [
        //             'Cache-Control' => 'no-cache',
        //             'Content-Type' => 'application/x-www-form-urlencoded',
        //         ],
        //     'body' => 'grant_type=trusted_merchant&client_id=' . $this->client_id . '&client_secret=' . $this->client_secret . '&email=' . $user->getBillableEmail() . '&ext_customer_id=' . $user->getBillableId(),
        // ]);
        // $data = json_decode($response->getBody(), true);
        // var_dump($data);
        // die();

        // // get or create plan
        // $stripeCustomer = $this->getStripeCustomer($user);

        // $cards = $stripeCustomer->sources->all(
        //     ['object' => 'card']
        // );

        // return empty($cards->data) ? NULL : $cards->data["0"];

        return null;
    }

    /**
     * Get the Stripe customer instance for the current user and token.
     *
     * @param  SubscriptionParam    $subscriptionParam
     * @return \Stripe\Customer
     */
    protected function getStripeCustomer($user)
    {
        // Find in gateway server
        $stripeCustomers = \Stripe\Customer::all();
        foreach ($stripeCustomers as $stripeCustomer) {
            if ($stripeCustomer->metadata->local_user_id == $user->getBillableId()) {
                return $stripeCustomer;
            }
        }

        // create if not exist
        $stripeCustomer = \Stripe\Customer::create([
            'email' => $user->getBillableEmail(),
            'metadata' => [
                'local_user_id' => $user->getBillableId(),
            ],
        ]);

        return $stripeCustomer;
    }

    /**
     * Update user card.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function billableUserUpdateCard($user, $params)
    {
        $stripeCustomer = $this->getStripeCustomer($user);

        $card = $stripeCustomer->sources->create(['source' => $params['stripeToken']]);
        $stripeCustomer->default_source = $card->id;
        $stripeCustomer->save();
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
            $subscription->last_error_type = PayuPaymentGateway::ERROR_RECURRING_CHARGE_FAILED;
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
        return $subscription->subscriptionTransactions()
            ->where('type', '<>', SubscriptionLog::TYPE_SUBSCRIBE)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Check if has pending transaction
     *
     * @return boolean
     */
    public function hasPending($subscription) {
        $transaction = $this->getLastTransaction($subscription);
        return isset($transaction) && $transaction->isPending() && !in_array($transaction->type, [
            SubscriptionTransaction::TYPE_SUBSCRIBE,
        ]);
    }

    public function getPendingNotice($subscription) {
        return false;
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
            case PayuPaymentGateway::ERROR_RECURRING_CHARGE_FAILED:
                return trans('cashier::messages.braintree.payment_error.recurring_charge_error', [
                    'url' => action('\Acelle\Cashier\Controllers\PayuController@fixPayment', [
                        'subscription_id' => $subscription->uid,
                    ]),
                ]);

                break;
            default:
                return trans('cashier::messages.braintree.error.something_went_wrong');
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

    /**
     * Gateway check method.
     *
     * @return void
     */
    public function check($subscription)
    {
        // check from service: recurring/transaction
        if ($subscription->isExpiring($this)) {
            $this->renew($subscription);
        }
    }

    /**
     * Check if use remote subscription.
     *
     * @return void
     */
    public function useRemoteSubscription()
    {
        return false;
    }
    
    /**
     * Get sig value.
     *
     * @return void
     */
    public function getSig($subscription)
    {
        // EURtest@test.comen145227TEST12345
        $str = '';
        // currency-code
        $str .= $subscription->plan->getBillableCurrency();
        // customer-email
        $str .= $subscription->user->getBillableEmail();
        // customer-language
        $str .= \Auth::user()->customer->getLanguageCode();
        // merchant-pos-id
        $str .= $this->client_id; 
        // recurring-payment
        $str .= 'true';
        // shop-name
        $str .= \Acelle\Model\Setting::get('site_name');    
        // store-card
        $str .= 'true';        
        // total-amount
        $str .= $subscription->plan->getBillableAmount();

        // second key
        $str .= $this->second_key;

        return hash('sha256', $str);
    }
}