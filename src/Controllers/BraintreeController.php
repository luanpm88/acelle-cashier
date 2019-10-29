<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;

class BraintreeController extends Controller
{
    /**
     * Get current payment service.
     *
     * @return \Illuminate\Http\Response
     **/
    public function getPaymentService()
    {
        return Cashier::getPaymentGateway('braintree');
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
        $request->session()->put('checkout_return_url', $request->return_url);
        
        $clientToken = $service->serviceGateway->clientToken()->generate();
        
        $cardInfo = $service->getCardInformation($subscription->user);
        
        //$tid = $service->getTransaction($subscription)['id'];
        //var_dump($service->serviceGateway->transaction()->find($tid));
        //die();
        
        return view('cashier::braintree.checkout', [
            'gatewayService' => $service,
            'subscription' => $subscription,
            'clientToken' => $clientToken,
            'cardInfo' => $cardInfo,
        ]);
    }
    
    /**
     * Update customer card.
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
        $service->updateCard($subscription->user, $request->nonce);

        return redirect()->action('\Acelle\Cashier\Controllers\BraintreeController@charge', [
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

        return view('cashier::braintree.charge', [
            'subscription' => $subscription,
        ]);
    }
    
    /**
     * Subscription pending page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function pending(Request $request, $subscription_id)
    {
        $service = $this->getPaymentService();
        $subscription = Subscription::findByUid($subscription_id);
        $transaction = $service->getTransaction($subscription);
        
        $service->sync($subscription);
        
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }
        
        if (!$subscription->isPending()) {
            return redirect()->away($return_url);
        }
        
        return view('cashier::braintree.pending', [
            'gatewayService' => $service,
            'subscription' => $subscription,
            'transaction' => $transaction,
            'return_url' => $return_url,
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
        $plan = \Acelle\Model\Plan::findByUid($request->plan_id);        
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // check if status is not pending
        if ($service->hasPending($subscription)) {
            return redirect()->away($request->return_url);
        }
        
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
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
            'return_url' => $return_url,
            'newPlan' => $plan,
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