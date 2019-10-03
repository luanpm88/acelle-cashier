<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;

class StripeController extends Controller
{
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
        
        $request->session()->put('checkout_return_url', $request->return_url);
        
        return view('cashier::stripe.checkout', [
            'gatewayService' => $this->getPaymentService(),
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
        $gatewayService = $this->getPaymentService();
        
        // update card
        $gatewayService->billableUserUpdateCard($subscription->user, $request->all());

        return redirect()->action('\Acelle\Cashier\Controllers\StripeController@charge', [
            'subscription_id' => $subscription->uid,
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
        $gatewayService = $this->getPaymentService();
        $return_url = $request->session()->get('checkout_return_url', url('/'));

        if ($request->isMethod('post')) {
            // subscribe to plan
            $gatewayService->charge($subscription);

            // Redirect to my subscription page
            return redirect()->away($return_url);
        }

        return view('cashier::stripe.charge', [
            'subscription' => $subscription,
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
            return redirect()->away($request->return_url);
        }
        
        if ($request->isMethod('post')) {
            $return_url = $request->session()->get('checkout_return_url', url('/'));
            if (!$return_url) {
                $return_url = url('/');
            }
            
            // change plan
            $service->changePlan($subscription, $plan);

            // Redirect to my subscription page
            return redirect()->away($return_url);
        }
        
        return view('cashier::stripe.change_plan', [
            'subscription' => $subscription,
            'plan_id' => $request->plan_id,
        ]);
    }
}