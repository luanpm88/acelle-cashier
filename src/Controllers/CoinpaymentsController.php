<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\SubscriptionTransaction;

class CoinpaymentsController extends Controller
{
    public function __construct()
    {
        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    /**
     * Get return url.
     *
     * @return string
     **/
    public function getReturnUrl(Request $request) {
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }

        return $return_url;
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
            return redirect()->away($this->getReturnUrl($request));
        }
        
        $service->sync($subscription);
        
        return view('cashier::coinpayments.checkout', [
            'gatewayService' => $service,
            'subscription' => $subscription,
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
            // add transaction
            $transaction = $subscription->addTransaction([
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.subscribed_to_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice()
            ]);
            
            // add remote transaction
            $result = $gatewayService->charge($subscription, [
                'id' => $transaction->uid,
                'amount' => $subscription->plan->getBillableAmount(),
                'desc' => trans('cashier::messages.coinpayments.subscribe_to_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),                
            ]);

            // update remote data
            $transaction->updateMetadata([
                'txn_id' => $result["txn_id"],
                'checkout_url' => $result["checkout_url"],
                'status_url' => $result["status_url"],
                'qrcode_url' => $result["qrcode_url"],
            ]);

            // set subscription is pending
            $subscription->setPending();

            return redirect()->away($result['checkout_url']);
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
        $transaction = $service->getInitTransaction($subscription);

        // get remote info
        $service->updateTransactionRemoteInfo($transaction);
        
        if (!$subscription->isPending()) {
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::coinpayments.pending', [
            'gatewayService' => $service,
            'subscription' => $subscription,
            'transaction' => $transaction,
            'return_url' => $this->getReturnUrl($request),
        ]);
    }

    /**
     * Subscription pending page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function transactionPending(Request $request, $subscription_id)
    {
        $service = $this->getPaymentService();
        $subscription = Subscription::findByUid($subscription_id);
        $transaction = $service->getLastTransaction($subscription);

        // get remote info
        $service->updateTransactionRemoteInfo($transaction);
        
        if (!$service->hasPending($subscription)) {
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::coinpayments.transactionPending', [
            'gatewayService' => $service,
            'subscription' => $subscription,
            'transaction' => $transaction,
            'return_url' => $this->getReturnUrl($request),
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
            // add transaction
            $transaction = $subscription->addTransaction([
                'ends_at' => $subscription->nextPeriod(),
                'current_period_ends_at' => $subscription->nextPeriod(),
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.renew_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            if ($subscription->plan->getBillableAmount() > 0) {
                // add remote transaction
                $result = $service->charge($subscription, [
                    'id' => $transaction->uid,
                    'amount' => $subscription->plan->getBillableAmount(),
                    'desc' => trans('cashier::messages.transaction.renew_plan', [
                        'plan' => $subscription->plan->getBillableName(),
                    ]),                
                ]);

                // update remote data
                $transaction->updateMetadata([
                    'txn_id' => $result["txn_id"],
                    'checkout_url' => $result["checkout_url"],
                    'status_url' => $result["status_url"],
                    'qrcode_url' => $result["qrcode_url"],
                ]);
            } elseif (round($result['amount']) == 0) {
                $service->approvePending($subscription);
                return redirect()->away($this->getReturnUrl($request));
            }

            return redirect()->away($result['checkout_url']);
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

        // calc plan before change
        try {
            $result = Cashier::calcChangePlan($subscription, $plan);
        } catch (\Exception $e) {
            $request->session()->flash('alert-error', 'Can not change plan: ' . $e->getMessage());
            return redirect()->away($request->return_url);
        }
        
        if ($request->isMethod('post')) {
            // add transaction
            $transaction = $subscription->addTransaction([
                'ends_at' => $result['endsAt'],
                'current_period_ends_at' => $result['endsAt'],
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.change_plan', [
                    'plan' => $plan->getBillableName(),
                ]),
                'amount' => $plan->getBillableFormattedPrice(),
            ]);

            // save new plan uid
            $data = $transaction->getMetadata();
            $data['plan_id'] = $plan->getBillableId();
            $transaction->updateMetadata($data);

            if ($result['amount'] > 0) {
                // add remote transaction
                $result = $service->charge($subscription, [
                    'id' => $transaction->uid,
                    'amount' => $result['amount'],
                    'desc' => trans('cashier::messages.transaction.change_plan', [
                        'plan' => $plan->getBillableName(),
                    ]),                
                ]);

                // update remote data
                $transaction->updateMetadata([
                    'txn_id' => $result["txn_id"],
                    'checkout_url' => $result["checkout_url"],
                    'status_url' => $result["status_url"],
                    'qrcode_url' => $result["qrcode_url"],
                ]);
            } elseif (round($result['amount']) == 0) {
                $service->approvePending($subscription);

                return redirect()->away($this->getReturnUrl($request));
            }

            return redirect()->away($result['checkout_url']);
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

        if ($subscription->isPending()) {
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