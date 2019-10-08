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
        
        if (isSiteDemo()) {
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
                'cancel_url' => 'https://example.com/cancel',
            ]);
            
            return view('cashier::stripe.checkout_demo', [
                'service' => $service,
                'session' => $session,
            ]);
        } else {
            return view('cashier::stripe.checkout', [
                'gatewayService' => $this->getPaymentService(),
                'subscription' => $subscription,
            ]);
        }
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