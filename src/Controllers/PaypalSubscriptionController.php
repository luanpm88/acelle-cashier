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
        $return_url = $request->session()->get('checkout_return_url', Cashier::public_url('/'));
        if (!$return_url) {
            $return_url = Cashier::public_url('/');
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
        if ($subscription->isActive() || $subscription->isEnded()) {
            return redirect()->away($this->getReturnUrl($request));
        }

        // get access token
        $accessToken = $service->getAccessToken();
        $paypalPlan = $service->getPaypalPlan($subscription->plan);

        if ($request->isMethod('post')) {
            try {
                // create subscription
                $paypalSubscription = $service->createPaypalSubscription($subscription, $request->subscriptionID);
            } catch(\Exception $e) {
                $request->session()->flash('alert-error', 
                    trans('cashier::messages.paypal_subscription.create_paypal_subscription_error', [
                        'error' => $e->getMessage()
                    ]
                ));
                return redirect()->away($service->getCheckoutUrl($subscription, $request));
            }   

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
        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        // @todo dependency injection 
        $plan = \Acelle\Model\Plan::findByUid($request->plan_id);        
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }

        // get access token
        $accessToken = $service->getAccessToken();
        $paypalPlan = $service->getPaypalPlan($plan);

        if ($request->isMethod('post')) {            
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

            // in case free plan (price == 0)
            if ($plan->getBillableAmount() == 0) {
                $transaction->setSuccess();

                // check new states
                $subscription->ends_at = null;

                // period date update
                if ($subscription->current_period_ends_at != $transaction->current_period_ends_at) {
                    // save last period
                    $subscription->last_period_ends_at = $subscription->current_period_ends_at;
                    // set new current period
                    $subscription->current_period_ends_at = $transaction->current_period_ends_at;
                }

                // check new plan
                $transactionData = $transaction->getMetadata();
                $oldPlan = $subscription->plan;
                if (isset($transactionData['plan_id'])) {
                    $subscription->plan_id = $transactionData['plan_id'];
                }

                // cancel old subscription
                $service->cancelPaypalSubscription($subscription);
                // add new subscription data
                $data = $subscription->getMetadata();
                $data['subscriptionID'] = null;
                $data['subscription'] = null;
                $subscription->updateMetadata($data);

                // save all
                $subscription->save();

                $subscription = Subscription::find($subscription->id);
                // add log
                aleep(10);
                $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGED, [
                    'old_plan' => $oldPlan->getBillableName(),
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);

                // Redirect to my subscription page
                return redirect()->away($this->getReturnUrl($request));
            }            

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

                // set subscription last_error_type
                $subscription->error = json_encode([
                    'status' => 'warning',
                    'type' => 'change_plan_pending',
                    'message' => trans('cashier::messages.paypal_subscription.has_transaction_pending.change_plan', [
                        'description' => $transaction->title,
                        'amount' => $transaction->amount,
                        'plan' => $plan->getBillableName(),
                        'url' => $service->getTransactionPendingUrl($subscription, \Acelle\Cashier\Cashier::lr_action('AccountSubscriptionController@index')),
                    ]),
                ]);
                $subscription->save();
            } catch (\Exception $e) {
                // set transaction failed
                $transaction->description = $e->getMessage();
                $transaction->setFailed();

                // add log
                $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGE_FAILED, [
                    'old_plan' => $subscription->plan->getBillableName(),
                    'plan' => $plan->getBillableName(),
                    'price' => $plan->getBillableFormattedPrice(),
                    'error' => $e->getMessage(),
                ]);

                // set subscription last_error_type
                $subscription->error = json_encode([
                    'status' => 'error',
                    'type' => 'change_plan_failed',
                    'message' => trans('cashier::messages.change_plan_failed_with_error', [
                        'error' => $e->getMessage(),
                    ]),
                ]);
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

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_CANCELLED_NOW, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);
        }

        // Redirect to my subscription page
        return redirect()->away($this->getReturnUrl($request));
    }
}