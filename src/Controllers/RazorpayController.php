<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;

class RazorpayController extends Controller
{
    public function __construct()
    {
        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    public function getReturnUrl(Request $request) {
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }

        return $return_url;
    }

    /**
     * Get current payment service.
     *
     * @return \Illuminate\Http\Response
     **/
    public function getPaymentService()
    {
        return Cashier::getPaymentGateway('razorpay');
    }
    
    /**
     * Subscription checkout page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function checkout(Request $request, $subscription_id)
    {
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        // save return url
        $request->session()->put('checkout_return_url', $request->return_url);

        $sub = $service->createRazorpaySubscription($subscription);
        var_dump($sub);
        die();


        // create order
        $order = $service->createRazorpayOrder($subscription);
        $customer = $service->createRazorpayCustomer($subscription);

        // if subscription is active
        if (!$subscription->isNew()) {
            return redirect()->away($this->getReturnUrl($request));
        }

        // if free plan
        if ($subscription->plan->getBillableAmount() == 0) {
            // charged successfully. Set subscription to active
            $subscription->start();

            // add transaction
            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_SUCCESS,
                'title' => trans('cashier::messages.transaction.subscribed_to_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice()
            ]);

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }

        // check payment
        if ($request->isMethod('post')) {            
            $sig = hash_hmac('sha256', $request->razorpay_order_id . "|" . $request->razorpay_payment_id, $service->key_secret);
            if ($sig == $request->razorpay_signature) {
                // charged successfully. Set subscription to active
                $subscription->start();

                // add transaction
                $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                    'ends_at' => $subscription->ends_at,
                    'current_period_ends_at' => $subscription->current_period_ends_at,
                    'status' => SubscriptionTransaction::STATUS_SUCCESS,
                    'title' => trans('cashier::messages.transaction.subscribed_to_plan', [
                        'plan' => $subscription->plan->getBillableName(),
                    ]),
                    'amount' => $subscription->plan->getBillableFormattedPrice()
                ]);
                
                sleep(1);
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBED, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);

                // Redirect to my subscription page
                return redirect()->away($this->getReturnUrl($request));
            }
        }

        return view('cashier::razorpay.checkout', [
            'service' => $this->getPaymentService(),
            'subscription' => $subscription,
            'order' => $order,
            'customer' => $customer,
        ]);
    }
    
    /**
     * Subscribe with card information.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function updateCard(Request $request, $subscription_id)
    {
        // subscription and service
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        // // update card
        // $service->billableUserUpdateCard($subscription->user, $request->all());

        // $service->getCardInformation($subscription->user);

        // return redirect()->away($request->redirect);

        // Get new one if not exist
        $uri = 'https://secure.payu.com/api/v2_1/orders';
        $client = new \GuzzleHttp\Client();
        $response = $client->request('POST', $uri, [
            'headers' =>
                [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $service->getAccessToken()
                ],
            'body' => '{
                "notifyUrl":"https://your.eshop.com/notify",
                "customerIp":"127.0.0.1",
                "merchantPosId":"' . $service->client_id . '",
                "recurring": "STANDARD",
                "description":"' . $subscription->plan->description . '",
                "currencyCode":"PLN",
                "totalAmount":"1900",
                "extOrderId":"' . uniqid() . '",
                "products":[
                   {
                      "name":"' . $subscription->plan->getBillableName() . '",
                      "unitPrice":"1900",
                      "quantity":"1"
                   }
                ],
                "buyer": {
                    "email": "' . $subscription->user->getBillableEmail() . '",
                    "firstName": "' . $subscription->user->displayName() . '",
                    "lastName": "' . $subscription->user->displayName() . '",
                    "language": "' . \Auth::user()->customer->getLanguageCode() . '"
                },                         
                "payMethods": {
                    "payMethod": {
                        "value": "' . $request->value . '",
                        "type": "CARD_TOKEN"
                    }
                }
            }'
        ], ['debug' => true]);
        $data = json_decode($response->getBody(), true);
        var_dump($data);
        die();
    }
    
    /**
     * Subscription charge.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function charge(Request $request, $subscription_id)
    {
        // subscription and service
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();

        if ($request->isMethod('post')) {
            $sig = hash_hmac('sha256', $request->razorpay_order_id . "|" . $request->razorpay_payment_id, $service->key_secret);
            if ($sig == $request->razorpay_signature) {
                // charged successfully. Set subscription to active
                $subscription->start();

                // add transaction
                $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                    'ends_at' => $subscription->ends_at,
                    'current_period_ends_at' => $subscription->current_period_ends_at,
                    'status' => SubscriptionTransaction::STATUS_SUCCESS,
                    'title' => trans('cashier::messages.transaction.subscribed_to_plan', [
                        'plan' => $subscription->plan->getBillableName(),
                    ]),
                    'amount' => $subscription->plan->getBillableFormattedPrice()
                ]);
                
                sleep(1);
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBED, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);

                // Redirect to my subscription page
                return redirect()->away($this->getReturnUrl($request));
            }
        }

        return view('cashier::payu.charge', [
            'subscription' => $subscription,
        ]);
    }
    
    /**
     * Change subscription plan.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function changePlan(Request $request, $subscription_id)
    {
        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        // @todo dependency injection 
        $newPlan = \Acelle\Model\Plan::findByUid($request->plan_id);
        
        // calc when change plan
        $result = Cashier::calcChangePlan($subscription, $newPlan);
        
        if ($request->isMethod('post')) {         
            // charge customer
            if ($result['amount'] > 0) {
                // charge customer
                $service->charge($subscription, [
                    'amount' => $result['amount'],
                    'currency' => $newPlan->getBillableCurrency(),
                    'description' => trans('cashier::messages.transaction.change_plan', [
                        'plan' => $newPlan->getBillableName(),
                    ]),
                ]);
            }
            
            // change plan
            $subscription->changePlan($newPlan);
            
            // add transaction
            $subscription->addTransaction(SubscriptionTransaction::TYPE_PLAN_CHANGE, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_SUCCESS,
                'title' => trans('cashier::messages.transaction.change_plan', [
                    'plan' => $newPlan->getBillableName(),
                ]),
                'amount' => $newPlan->getBillableFormattedPrice()
            ]);

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGED, [
                'old_plan' => $subscription->plan->getBillableName(),
                'plan' => $newPlan->getBillableName(),
                'price' => $newPlan->getBillableFormattedPrice(),
            ]);

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::payu.change_plan', [
            'subscription' => $subscription,
            'return_url' => $this->getReturnUrl($request),
            'newPlan' => $newPlan,
            'nextPeriodDay' => $result['endsAt'],
            'service' => $service,
            'amount' => $result['amount'],
        ]);
    }
    
    /**
     * Change subscription plan pending page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function changePlanPending(Request $request, $subscription_id)
    {
        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        return view('cashier::payu.change_plan_pending', [
            'subscription' => $subscription,
            'plan_id' => $request->plan_id,
        ]);
    }
    
    /**
     * Payment redirecting.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function paymentRedirect(Request $request)
    {
        return view('cashier::payu.payment_redirect', [
            'redirect' => $request->redirect,
        ]);
    }
    
    /**
     * Cancel new subscription.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function cancelNow(Request $request, $subscription_id)
    {
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();

        if ($subscription->isNew()) {
            $subscription->setEnded();
        }

        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }

        // Redirect to my subscription page
        return redirect()->away($return_url);
    }

    /**
     * Fix transation.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function fixPayment(Request $request, $subscription_id)
    {
        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        if ($request->isMethod('post')) {
            // try to renew again
            $ok = $service->renew($subscription);

            if ($ok) {
                // remove last_error
                $subscription->last_error_type = null;
                $subscription->save();
            }

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::payu.fix_payment', [
            'subscription' => $subscription,
            'return_url' => $this->getReturnUrl($request),
            'service' => $service,
        ]);
    }
}