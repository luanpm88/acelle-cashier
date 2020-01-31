<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\SubscriptionTransaction;

class StripeController extends Controller
{
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
        return Cashier::getPaymentGateway('stripe');
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
        
        // save return url
        $request->session()->put('checkout_return_url', $request->return_url);

        // if free plan
        if ($subscription->plan->getBillableAmount() == 0) {
            // charged successfully. Set subscription to active
            $subscription->start();

            // add transaction
            $subscription->addTransaction([
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_SUCCESS,
                'description' => trans('cashier::messages.transaction.subscribed_to_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice()
            ]);

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }
        
        // for demo site only
        if (isSiteDemo()) {
            if ($subscription->plan->price == 0) {
                $subscription->delete();

                $request->session()->flash('alert-error', trans('messages.operation_not_allowed_in_demo'));
                return redirect()->action('\Acelle\Http\Controllers\AccountSubscriptionController@selectPlan');
            }

            $service = $this->getPaymentService();
            \Stripe\Stripe::setApiVersion("2017-04-06");
            
            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                  'name' => $subscription->plan->getBillableName(),
                  'description' => \Acelle\Model\Setting::get('site_name'),
                  'images' => ['https://b.imge.to/2019/10/05/vE0yqs.png'],
                  'amount' => $subscription->plan->stripePrice(),
                  'currency' => $subscription->plan->getBillableCurrency(),
                  'quantity' => 1,
                ]],
                'success_url' => url('/'),
                'cancel_url' => action('\Acelle\Http\Controllers\AccountSubscriptionController@index'),
            ]);
            
            $subscription->delete();

            return view('cashier::stripe.checkout_demo', [
                'service' => $service,
                'session' => $session,
            ]);
        }

        return view('cashier::stripe.checkout', [
            'service' => $this->getPaymentService(),
            'subscription' => $subscription,
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
        
        // update card
        $service->billableUserUpdateCard($subscription->user, $request->all());

        return redirect()->away($request->redirect);
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
        $return_url = $request->session()->get('checkout_return_url', url('/'));

        if ($request->isMethod('post')) {
            // subscribe to plan
            $service->charge($subscription);

            // charged successfully. Set subscription to active
            $subscription->start();

            // add transaction
            $subscription->addTransaction([
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_SUCCESS,
                'description' => trans('cashier::messages.transaction.subscribed_to_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice()
            ]);

            // Redirect to my subscription page
            return redirect()->away($return_url);
        }

        return view('cashier::stripe.charge', [
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
            // change plan
            $service->changePlan($subscription, $newPlan);

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::stripe.change_plan', [
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
        
        return view('cashier::stripe.change_plan_pending', [
            'subscription' => $subscription,
            'plan_id' => $request->plan_id,
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