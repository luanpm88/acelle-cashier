<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;
use Acelle\Cashier\Services\PaypalSubscriptionPaymentGateway;

class PaypalSubscriptionController extends Controller
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
        return Cashier::getPaymentGateway('paypal_subscription');
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
     * Subscription checkout page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function checkout(Request $request, $subscription_id)
    {
        $service = $this->getPaymentService();
        // get access token
        $service->getAccessToken();

        $subscription = Subscription::findByUid($subscription_id);
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // if subscription is active
        if ($subscription->isActive()) {
            return redirect()->away($this->getReturnUrl($request));
        }

        // get access token
        $accessToken = $service->getAccessToken();
        $paypalPlan = $service->getPaypalPlan($subscription->plan);

        if ($request->isMethod('post')) {
            // create subscription
            $paypalSubscription = $service->createPaypalSubscription($subscription, $request->subscriptionID);

            // add transaction
            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.subscribe_to_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice()
            ]);

            // set pending
            $subscription->setPending();

            // Redirect to my subscription page
            return redirect()->away($service->getPendingUrl($subscription, $request));
        }
        
        return view('cashier::paypal_subscription.checkout', [
            'service' => $service,
            'subscription' => $subscription,
            'return_url' => $this->getReturnUrl($request),
            'accessToken' => $accessToken,
            'paypalPlan' => $paypalPlan,
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
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        if (!$subscription->isPending() || !$transaction->isPending()) {
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::paypal_subscription.pending', [
            'service' => $service,
            'subscription' => $subscription,
            'transaction' => $transaction,
            'paypalSubscription' => $subscription->getMetadata()['subscription'],
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
    public function changePlan(Request $request, $subscription_id)
    {
        // $request->session()->flash('alert-error', trans('cashier::messages.paypal.not_support_change_plan_yet'));
        // return redirect()->away($this->getReturnUrl($request));

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
            return redirect()->away($this->getReturnUrl($request));
        }

        // get access token
        $accessToken = $service->getAccessToken();
        $paypalPlan = $service->getPaypalPlan($plan);

        if ($request->isMethod('post')) {
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGE, [
                'old_plan' => $subscription->plan->getBillableName(),
                'plan' => $plan->getBillableName(),
                'price' => $plan->getBillableAmount(),
            ]);
            
            // add transaction
            $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_PLAN_CHANGE, [
                'ends_at' => null,
                'current_period_ends_at' => $subscription->getPeriodEndsAt(\Carbon\Carbon::now()),
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.change_plan', [
                    'old_plan' => $subscription->plan->getBillableName(),
                    'plan' => $plan->getBillableName(),
                ]),
                'amount' => $plan->getBillableAmount(),
            ]);

            // save new plan uid
            $data = $transaction->getMetadata();
            $data['plan_id'] = $plan->getBillableId();
            $transaction->updateMetadata($data);

            try {
                // create subscription
                $paypalSubscription = $service->changePlan($subscription, $plan, $request->subscriptionID);

                // save subscription data
                $data = $transaction->getMetadata();
                $data['paypal_subscription'] = $paypalSubscription;
                $data['subscriptionID'] = $request->subscriptionID;
                $transaction->updateMetadata($data);
            } catch (\Exception $e) {
                // set transaction failed
                $transaction->description = $e->getMessage();
                $transaction->setFailed();

                // add log
                $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANG_FAILED, [
                    'old_plan' => $subscription->plan->getBillableName(),
                    'plan' => $plan->getBillableName(),
                    'price' => $plan->getBillableFormattedPrice(),
                    'error' => $e->getMessage(),
                ]);

                // set subscription last_error_type
                $subscription->last_error_type = PaypalSubscriptionPaymentGateway::ERROR_CHARGE_FAILED;
                $subscription->save();

                // Redirect to my subscription page
                return redirect()->away($this->getReturnUrl($request));
            }

            // Redirect to transaction pending
            return redirect()->away($service->getTransactionPendingUrl($subscription, $this->getReturnUrl($request)));
        }
        
        return view('cashier::paypal_subscription.change_plan', [
            'service' => $service,
            'subscription' => $subscription,
            'newPlan' => $plan,
            'return_url' => $this->getReturnUrl($request),
            'nextPeriodDay' => $subscription->getPeriodEndsAt(\Carbon\Carbon::now()),
            'amount' => $plan->getBillableFormattedPrice(),
            'accessToken' => $accessToken,
            'paypalPlan' => $paypalPlan,
        ]);
    }

    /**
     * Payment redirecting.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function paymentRedirect(Request $request)
    {
        return view('cashier::paypal_subscription.payment_redirect', [
            'redirect' => $request->redirect,
        ]);
    }

    /**
     * Transaction pending.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function transactionPending(Request $request, $subscription_id)
    {
        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        $transaction = $service->getLastTransaction($subscription);

        if (!$transaction->isPending()) {
            return redirect()->away($this->getReturnUrl($request));
        }

        return view('cashier::paypal_subscription.transactionPending', [
            'subscription' => $subscription,
            'transaction' => $transaction,
            'paypalSubscription' => $transaction->getMetadata()['paypal_subscription'],
            'return_url' => $this->getReturnUrl($request),
        ]);
    }
}