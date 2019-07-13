<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;

class DirectController extends Controller
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
        return Cashier::getPaymentGateway('direct');
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
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // if subscription is active
        if ($subscription->isActive()) {
            $return_url = $request->session()->get('checkout_return_url', url('/'));
            if (!$return_url) {
                $return_url = url('/');
            }
            return redirect()->away($return_url);
        }
        
        $transaction = $service->getTransaction($subscription);
        
        return view('cashier::direct.checkout', [
            'gatewayService' => $service,
            'subscription' => $subscription,
            'transaction' => $transaction,
        ]);
    }
    
    /**
     * Claim payment.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function claim(Request $request, $subscription_id)
    {
        // subscription and service
        $subscription = Subscription::findByUid($subscription_id);
        $gatewayService = $this->getPaymentService();
        
        $gatewayService->claim($subscription);
        
        return redirect()->action('\Acelle\Cashier\Controllers\DirectController@checkout', [
            'subscription_id' => $subscription->uid,
        ]);
    }
    
    /**
     * Unclaim payment.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function unclaim(Request $request, $subscription_id)
    {
        // subscription and service
        $subscription = Subscription::findByUid($subscription_id);
        $gatewayService = $this->getPaymentService();
        
        $gatewayService->unclaim($subscription);
        
        return redirect()->action('\Acelle\Cashier\Controllers\DirectController@checkout', [
            'subscription_id' => $subscription->uid,
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
        
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }
        
        if ($transaction['status'] != 'pending') {            
            return redirect()->away($return_url);
        }
        
        return view('cashier::direct.pending', [
            'gatewayService' => $service,
            'subscription' => $subscription,
            'transaction' => $transaction,
            'data' => json_decode($transaction['data'], true),
            'return_url' => $return_url,
        ]);
    }
    
    /**
     * Claim payment.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function pendingClaim(Request $request, $subscription_id)
    {
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        $transaction = $service->getTransaction($subscription);
        
        $service->pendingClaim($transaction["ID"]);
        
        return redirect()->action('\Acelle\Cashier\Controllers\DirectController@pending', [
            'subscription_id' => $transaction['subscription_id'],
        ]);
    }
    
    /**
     * Unclaim payment.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function pendingUnclaim(Request $request, $subscription_id)
    {
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        $transaction = $service->getTransaction($subscription);
        
        $service->pendingUnclaim($transaction["ID"]);
        
        return redirect()->action('\Acelle\Cashier\Controllers\DirectController@pending', [
            'subscription_id' => $transaction['subscription_id'],
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
        
        if ($request->isMethod('post')) {
            // subscribe to plan
            $service->renew($subscription);

            // Redirect to my subscription page
            return redirect()->action('\Acelle\Cashier\Controllers\DirectController@pending', [
                'subscription_id' => $subscription->uid,
            ]);
        }
        
        return view('cashier::direct.renew', [
            'service' => $service,
            'subscription' => $subscription,
            'return_url' => $request->return_url,
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
        $plan = Cashier::findPlan($request->plan_id);        
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // check if status is not pending
        if ($service->hasPending($subscription)) {
            return redirect()->away($request->return_url);
        }
        
        if ($request->isMethod('post')) {
            // change plan
            $service->changePlan($subscription, $plan);

            // Redirect to my subscription page
            return redirect()->action('\Acelle\Cashier\Controllers\DirectController@pending', [
                'subscription_id' => $subscription->uid,
            ]);
        }
        
        // calc plan before change
        $result = Cashier::calcChangePlan($subscription, $plan);
        $plan->price = $result['amount'];
        
        return view('cashier::direct.change_plan', [
            'service' => $service,
            'subscription' => $subscription,
            'newPlan' => $plan,
            'return_url' => $request->return_url,
            'nextPeriodDay' => $result['endsAt'],
            'amount' => $plan->getBillableFormattedPrice(),
        ]);
    }
}