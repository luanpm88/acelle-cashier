<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;

class CoinpaymentsController extends Controller
{
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
        
        $transaction = $service->sync($subscription);
        
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
        $return_url = $request->session()->get('checkout_return_url', url('/'));

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
}