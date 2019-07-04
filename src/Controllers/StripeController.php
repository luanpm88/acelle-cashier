<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;

class StripeController extends Controller
{
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
        $gatewayService = \App::make('Acelle\Cashier\PaymentGateway');
        
        $request->session()->put('checkout_return_url', $request->return_url);
        // var_dump($request->session()->get('return_url', 'ddd'));
        
        return view('cashier::stripe.checkout', [
            'gatewayService' => $gatewayService,
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
        $gatewayService = \App::make('Acelle\Cashier\PaymentGateway');
        
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
        $gatewayService = \App::make('Acelle\Cashier\PaymentGateway');
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