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

        // create order
        $order = $service->createRazorpayOrder($subscription);
        $customer = $service->getRazorpayCustomer($subscription);

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
                $subscription->setActive();

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
     * Renew subscription.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function renew(Request $request, $subscription_id)
    {
        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // check if status is not pending
        if ($service->hasPending($subscription)) {
            return redirect()->away($this->getReturnUrl($request));
        }
        
        if ($request->isMethod('post')) {            
            // add transaction
            $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_RENEW, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.renew_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice()
            ]);

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_RENEW, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            if ($subscription->plan->price > 0) {      
                $sig = hash_hmac('sha256', $request->razorpay_order_id . "|" . $request->razorpay_payment_id, $service->key_secret);
                if ($sig == $request->razorpay_signature) {              
                    // add log
                    $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                    ]);
                } else {
                    // set transaction failed
                    $transaction->description = 'Can not verify remote order: ' . $request->razorpay_order_id;
                    $transaction->setFailed();                    
                    
                    // add log
                    sleep(1);
                    $subscription->addLog(SubscriptionLog::TYPE_RENEW_FAILED, [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                        'error' => 'Can not verify remote order: ' . $request->razorpay_order_id,
                    ]);

                    // set subscription last_error_type
                    $subscription->last_error_type = RazorpayPaymentGateway::ERROR_CHARGE_FAILED;
                    $subscription->save();

                    // Redirect to my subscription page
                    return redirect()->away($this->getReturnUrl($request));
                }
            }
            
            // renew
            $subscription->renew();

            // set success
            $transaction->setSuccess();
            
            sleep(1);
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_RENEWED, [
                'old_plan' => $subscription->plan->getBillableName(),
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }
        
        // create order
        $order = $service->createRazorpayOrder($subscription);
        $customer = $service->getRazorpayCustomer($subscription);

        return view('cashier::razorpay.renew', [
            'service' => $service,
            'subscription' => $subscription,
            'return_url' => $request->return_url,
            'order' => $order,
            'customer' => $customer,
        ]);
    }

    /**
     * Renew subscription.
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
        $plan = \Acelle\Model\Plan::findByUid($request->plan_id);        
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // check if status is not pending
        if ($service->hasPending($subscription)) {
            return redirect()->away($this->getReturnUrl($request));
        }

        // calc plan before change
        try {
            $result = Cashier::calcChangePlan($subscription, $plan);
        } catch (\Exception $e) {
            $request->session()->flash('alert-error', 'Can not change plan: ' . $e->getMessage());
            return redirect()->away($this->getReturnUrl($request));
        }

        if ($request->isMethod('post')) {
            // add transaction
            $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_PLAN_CHANGE, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.change_plan', [
                    'old_plan' => $subscription->plan->getBillableName(),
                    'plan' => $plan->getBillableName(),
                ]),
                'amount' => $result['amount']
            ]);

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGE, [
                'old_plan' => $subscription->plan->getBillableName(),
                'plan' => $plan->getBillableName(),
                'price' => $plan->getBillableFormattedPrice(),
            ]);

            // charge
            if ($result['amount'] > 0) {
                $sig = hash_hmac('sha256', $request->razorpay_order_id . "|" . $request->razorpay_payment_id, $service->key_secret);
                if ($sig == $request->razorpay_signature) {
                    sleep(1);
                    // add log
                    $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                        'plan' => $plan->getBillableName(),
                        'price' => $result['amount'],
                    ]);
                } else {
                    // set transaction failed
                    $transaction->description = 'Can not verify remote order: ' . $request->razorpay_order_id;
                    $transaction->setFailed();                    
                    
                    // add log
                    sleep(1);
                    $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANG_FAILED, [
                        'old_plan' => $subscription->plan->getBillableName(),
                        'plan' => $plan->getBillableName(),
                        'price' => $result['amount'],
                        'error' => 'Can not verify remote order: ' . $request->razorpay_order_id,
                    ]);

                    // set subscription last_error_type
                    $subscription->last_error_type = PaypalPaymentGateway::ERROR_CHARGE_FAILED;
                    $subscription->save();

                    // Redirect to my subscription page
                    return redirect()->away($this->getReturnUrl($request));
                }
            }
            
            // change plan
            $subscription->changePlan($plan, round($result['amount']));
            
            // set success
            $transaction->setSuccess();

            sleep(1);
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGED, [
                'plan' => $plan->getBillableName(),
                'price' => $plan->getBillableFormattedPrice(),
            ]);

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }

        $plan->price = round($result['amount']);

        // create order
        $order = $service->createRazorpayOrder($subscription, $plan);
        $customer = $service->getRazorpayCustomer($subscription);
        
        return view('cashier::razorpay.change_plan', [
            'service' => $service,
            'subscription' => $subscription,
            'newPlan' => $plan,
            'return_url' => $request->return_url,
            'nextPeriodDay' => $result['endsAt'],
            'amount' => $plan->getBillableFormattedPrice(),
            'order' => $order,
            'customer' => $customer,
        ]);
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
}