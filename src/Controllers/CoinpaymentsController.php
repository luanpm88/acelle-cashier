<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;

class CoinpaymentsController extends Controller
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
        return Cashier::getPaymentGateway('coinpayments');
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
            return redirect()->away($request->session()->get('checkout_return_url'));
        }
        
        $service->sync($subscription);
        $transaction = $service->getInitTransaction($subscription);
        
        return view('cashier::coinpayments.checkout', [
            'gatewayService' => $service,
            'subscription' => $subscription,
            'transaction' => $transaction,
            'return_url' => $request->session()->get('checkout_return_url'),
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

        if ($request->isMethod('post')) {
            $transaction = $gatewayService->charge($subscription);

            // Redirect to checkout page
            //return redirect()->action('\Acelle\Cashier\Controllers\CoinpaymentsController@checkout', [
            //    'subscription_id' => $subscription->uid,
            //]);
            
            return redirect()->away($transaction['checkout_url']);
        }

        return view('cashier::coinpayments.charge', [
            'subscription' => $subscription,
            'gatewayService' => $gatewayService,
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
        
        if (!$service->hasPending($subscription)) {
            return redirect()->away($return_url);
        }
        
        return view('cashier::coinpayments.pending', [
            'gatewayService' => $service,
            'subscription' => $subscription,
            'transaction' => $transaction,
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
    public function renew(Request $request, $subscription_id)
    {
        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // make sure status is not pending
        if ($service->hasPending($subscription)) {
            return redirect()->away($request->return_url);
        }
        
        if ($request->isMethod('post')) {
            // subscribe to plan
            $service->renew($subscription);

            // Redirect to my subscription page
            return redirect()->action('\Acelle\Cashier\Controllers\CoinpaymentsController@pending', [
                'subscription_id' => $subscription->uid,
            ]);
        }
        
        return view('cashier::coinpayments.renew', [
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
            // change plan
            $service->changePlan($subscription, $plan);

            // Redirect to my subscription page
            return redirect()->action('\Acelle\Cashier\Controllers\CoinpaymentsController@pending', [
                'subscription_id' => $subscription->uid,
            ]);
        }
        
        // calc plan before change
        try {
            $result = Cashier::calcChangePlan($subscription, $plan);
        } catch (\Exception $e) {
            $request->session()->flash('alert-error', 'Can not change plan: ' . $e->getMessage());
            return redirect()->away($request->return_url);
        }
        $plan->price = $result['amount'];
        
        return view('cashier::coinpayments.change_plan', [
            'service' => $service,
            'subscription' => $subscription,
            'newPlan' => $plan,
            'return_url' => $request->return_url,
            'nextPeriodDay' => $result['endsAt'],
            'amount' => $plan->getBillableFormattedPrice(),
        ]);
    }
}