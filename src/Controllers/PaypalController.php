<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;

class PaypalController extends Controller
{
    public function __construct()
    {
        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    /**
     * Get current payment service.
     *
     * @return \Illuminate\Http\Response
     **/
    public function getPaymentService()
    {
        return Cashier::getPaymentGateway('paypal');
    }

    /**
     * Get return url.
     *
     * @return string
     **/
    public function getReturnUrl(Request $request) {
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }

        return $return_url;
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
        $service = $this->getPaymentService();
        $subscription = Subscription::findByUid($subscription_id);

        // return url
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // if subscription is active
        if ($subscription->isActive()) {
            return redirect()->away($this->getReturnUrl($request));
        }

        if ($request->isMethod('post')) {
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBE, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            // throw excaption of failed
            if ($subscription->plan->price > 0) {
                $service->charge($subscription, [
                    'orderID' => $request->orderID,
                ]);

                sleep(1);
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
            }

            // charged successfully. Set subscription to active
            $subscription->start();

            // add transaction
            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBED, [
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
            $subscription->addLog(SubscriptionLog::TYPE_RENEWED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::paypal.checkout', [
            'gatewayService' => $service,
            'subscription' => $subscription,
            'return_url' => $return_url,
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

        // return url
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }

        // calc plan before change
        try {
            $result = Cashier::calcChangePlan($subscription, $plan);
        } catch (\Exception $e) {
            $request->session()->flash('alert-error', 'Can not change plan: ' . $e->getMessage());
            return redirect()->away($return_url);
        }

        if ($request->isMethod('post')) {
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGE, [
                'old_plan' => $subscription->plan->getBillableName(),
                'plan' => $plan->getBillableName(),
                'price' => $plan->getBillableFormattedPrice(),
            ]);

            // charge
            if (round($result['amount']) > 0) {
                $service->charge($subscription, [
                    'orderID' => $request->orderID,
                ]);

                sleep(1);
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                    'plan' => $plan->getBillableName(),
                    'price' => $plan->getBillableFormattedPrice(),
                ]);
            }
            
            // change plan
            $subscription->changePlan($plan, round($result['amount']));
            
            // add transaction
            $subscription->addTransaction(SubscriptionTransaction::TYPE_PLAN_CHANGE, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_SUCCESS,
                'title' => trans('cashier::messages.transaction.change_plan', [
                    'plan' => $plan->getBillableName(),
                ]),
                'amount' => $plan->getBillableFormattedPrice()
            ]);

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
        
        return view('cashier::paypal.change_plan', [
            'service' => $service,
            'subscription' => $subscription,
            'newPlan' => $plan,
            'return_url' => $request->return_url,
            'nextPeriodDay' => $result['endsAt'],
            'amount' => $plan->getBillableFormattedPrice(),
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
            return redirect()->away($request->return_url);
        }

        // return url
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }
        
        if ($request->isMethod('post')) {
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_RENEW, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            if ($subscription->plan->price > 0) {
                // check order ID
                $service->checkOrderId($request->orderID);
                
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
            }
            
            // renew
            $subscription->renew();

            // subscribe to plan
            $subscription->addTransaction(SubscriptionTransaction::TYPE_RENEW, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_SUCCESS,
                'title' => trans('cashier::messages.transaction.renew_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice(),
            ]);
            
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
        
        return view('cashier::paypal.renew', [
            'service' => $service,
            'subscription' => $subscription,
            'return_url' => $request->return_url,
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
        return view('cashier::paypal.payment_redirect', [
            'redirect' => $request->redirect,
        ]);
    }
}