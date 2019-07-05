<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;

class DirectController extends Controller
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
        
        // Save return url
        $request->session()->put('checkout_return_url', $request->return_url);
        
        return view('cashier::direct.checkout', [
            'gatewayService' => $gatewayService,
            'subscription' => $subscription,
        ]);
    }
}