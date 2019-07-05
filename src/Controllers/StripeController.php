<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;

class StripeController extends Controller
{
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
}