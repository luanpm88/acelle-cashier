<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;

class PaypalController extends Controller
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
        return Cashier::getPaymentGateway('paypal');
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

        // return url
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // if subscription is active
        if ($subscription->isActive()) {            
            return redirect()->away($return_url);
        }

        if ($request->isMethod('post')) {
            try {
                $service->charge($subscription, [
                    'orderID' => $request->orderID,
                ]);
            } catch (\Exception $e) {
                echo $e->getMessage();
                die();
            }
            
            // save subscription
            $subscription->setActive();

            // return to dasboard
            return redirect()->away($return_url);
        }
        
        return view('cashier::paypal.checkout', [
            'gatewayService' => $service,
            'subscription' => $subscription,
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

        // return url
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }
        

        // calc plan before change
        try {
            $result = Cashier::calcChangePlan($subscription, $plan);
        } catch (\Exception $e) {
            $request->session()->flash('alert-error', 'Can not change plan: ' . $e->getMessage());
            return redirect()->away($return_url);
        }
        
        $plan->price = round($result['amount']);

        if ($request->isMethod('post')) {
            if ($plan->price > 0) {
                // check order ID
                $service->checkOrderId($request->orderID);
            }

            // change plan
            $service->changePlan($subscription, $plan);

            // Redirect to my subscription page
            return redirect()->away($return_url);
        }
        
        return view('cashier::paypal.change_plan', [
            'service' => $service,
            'subscription' => $subscription,
            'newPlan' => $plan,
            'return_url' => $request->return_url,
            'nextPeriodDay' => $result['endsAt'],
            'amount' => $plan->getBillableFormattedPrice(),
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

        // return url
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }
        
        if ($request->isMethod('post')) {
            if ($subscription->plan->price > 0) {
                // check order ID
                $service->checkOrderId($request->orderID);
            }

            // subscribe to plan
            $service->renew($subscription);

            // Redirect to my subscription page
            return redirect()->away($return_url);
        }
        
        return view('cashier::paypal.renew', [
            'service' => $service,
            'subscription' => $subscription,
            'return_url' => $request->return_url,
        ]);
    }
}