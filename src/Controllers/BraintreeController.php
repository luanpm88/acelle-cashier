<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Services\BraintreePaymentGateway;

class BraintreeController extends Controller
{
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
        return Cashier::getPaymentGateway('braintree');
    }

    /**
     * Subscription checkout page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function checkout2(Request $request, $subscription_id)
    {
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        $request->session()->put('checkout_return_url', $request->return_url);
        
        $clientToken = $service->serviceGateway->clientToken()->generate();
        
        $cardInfo = $service->getCardInformation($subscription->user);
        
        //$tid = $service->getTransaction($subscription)['id'];
        //var_dump($service->serviceGateway->transaction()->find($tid));
        //die();
        
        return view('cashier::braintree.checkout', [
            'gatewayService' => $service,
            'subscription' => $subscription,
            'clientToken' => $clientToken,
            'cardInfo' => $cardInfo,
        ]);
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
        $service = $this->getPaymentService();
        
        // save return url
        $request->session()->put('checkout_return_url', $request->return_url);

        // if subscription is active
        if ($subscription->isActive() || $subscription->isEnded()) {
            return redirect()->away($this->getReturnUrl($request));
        }

        // if free plan
        if ($subscription->plan->getBillableAmount() == 0 && $service->always_ask_for_valid_card == 'no') {
            // charged successfully. Set subscription to active
            $subscription->start();

            // add transaction
            $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_SUCCESS,
                'title' => trans('cashier::messages.transaction.subscribed_to_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice()
            ]);

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }

        return view('cashier::braintree.checkout', [
            'service' => $service,
            'subscription' => $subscription,
            'clientToken' => $service->serviceGateway->clientToken()->generate(),
            'cardInfo' => $service->getCardInformation($subscription->user),
        ]);
    }
    
    /**
     * Update customer card.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function updateCard(Request $request, $subscription_id)
    {
        // subscription and service
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        // update card
        $service->updateCard($subscription->user, $request->nonce);
        
        // charge url
        if ($request->charge_url) {
            return redirect()->away($request->charge_url);
        }
        
        return redirect()->away($request->redirect);
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
        $service = $this->getPaymentService();
        $return_url = $request->session()->get('checkout_return_url', url('/'));

        if ($request->isMethod('post')) {
            // add transaction
            $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.subscribed_to_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice()
            ]);
            
            if ($subscription->plan->getBillableAmount() > 0) {
                try {
                    // charge customer
                    $service->charge($subscription, [
                        'amount' => $subscription->plan->getBillableAmount(),
                        'currency' => $subscription->plan->getBillableCurrency(),
                        'description' => trans('cashier::messages.transaction.subscribed_to_plan', [
                            'plan' => $subscription->plan->getBillableName(),
                        ]),
                    ]);
                } catch (\Exception $e) {
                    // charged successfully. Set subscription to active
                    $subscription->cancelNow();

                    // transaction success
                    $transaction->description = trans('cashier::messages.charge.something_went_wrong', ['error' => $e->getMessage()]);
                    $transaction->setFailed();

                    // add log
                    $subscription->addLog(SubscriptionLog::TYPE_ERROR, [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                        'message' => $e->getMessage(),
                    ]);

                    // Redirect to my subscription page
                    $request->session()->flash('alert-error', trans('cashier::messages.charge.something_went_wrong', ['error' => $e->getMessage()]));
                    return redirect()->away($this->getReturnUrl($request));
                }
            }

            // charged successfully. Set subscription to active
            $subscription->start();

            // transaction success
            $transaction->setSuccess();

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);
            sleep(1);
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }

        return view('cashier::braintree.charge', [
            'subscription' => $subscription,
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
        
        $service->sync($subscription);
        
        $return_url = $request->session()->get('checkout_return_url', url('/'));
        if (!$return_url) {
            $return_url = url('/');
        }
        
        if (!$subscription->isPending()) {
            return redirect()->away($return_url);
        }
        
        return view('cashier::braintree.pending', [
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
    public function changePlan(Request $request, $subscription_id)
    {
        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        $cardInfo = $service->getCardInformation($subscription->user);
        
        // @todo dependency injection
        $plan = \Acelle\Model\Plan::findByUid($request->plan_id);        
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }

        // calc plan before change
        try {
            $result = Cashier::calcChangePlan($subscription, $plan);
        } catch (\Exception $e) {
            $request->session()->flash('alert-error', trans('cashier::messages.change_plan.failed', ['error' => $e->getMessage()]));
            return redirect()->away($this->getReturnUrl($request));
        }
        
        if ($request->isMethod('post')) {
            // add transaction
            $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_PLAN_CHANGE, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.change_plan', [
                    'plan' => $plan->getBillableName(),
                ]),
                'amount' => $result['amount'],
            ]);

            // charge customer
            if ($result['amount'] > 0) {
                try {
                    // charge customer
                    $service->charge($subscription, [
                        'amount' => $result['amount'],
                        'currency' => $plan->getBillableCurrency(),
                        'description' => trans('cashier::messages.transaction.change_plan', [
                            'plan' => $plan->getBillableName(),
                        ]),
                    ]);
                } catch (\Exception $e) {
                    // set transaction failed
                    $transaction->description = $e->getMessage();
                    $transaction->setFailed();

                    // add log
                    $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGE_FAILED, [
                        'old_plan' => $subscription->plan->getBillableName(),
                        'plan' => $plan->getBillableName(),
                        'price' => $result['amount'],
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
            }
            
            // change plan
            $subscription->changePlan($plan);

            // set success
            $transaction->setSuccess();

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGED, [
                'old_plan' => $subscription->plan->getBillableName(),
                'plan' => $plan->getBillableName(),
                'price' => $plan->getBillableFormattedPrice(),
            ]);
            
            // remove last_error
            $subscription->error = null;
            $subscription->save();

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::braintree.change_plan', [
            'service' => $service,
            'subscription' => $subscription,
            'newPlan' => $plan,
            'return_url' => $request->return_url,
            'nextPeriodDay' => $result['endsAt'],
            'amount' => $result['amount'],
            'cardInfo' => $cardInfo,
            'clientToken' => $service->serviceGateway->clientToken()->generate(),
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
        
        return view('cashier::braintree.change_plan_pending', [
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
        
        if ($request->isMethod('post')) {
            // subscribe to plan
            $service->renew($subscription);

            // Redirect to my subscription page
            return redirect()->action('\Acelle\Cashier\Controllers\BraintreeController@pending', [
                'subscription_id' => $subscription->uid,
            ]);
        }
        
        // card info
        $cardInfo = $service->getCardInformation($subscription->user);
        $clientToken = $service->serviceGateway->clientToken()->generate();
        
        return view('cashier::braintree.renew', [
            'service' => $service,
            'subscription' => $subscription,
            'return_url' => $request->return_url,
            'cardInfo' => $cardInfo,
            'clientToken' => $clientToken,
        ]);
    }
    
    /**
     * Change subscription plan pending page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function renewPending(Request $request, $subscription_id)
    {
        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        return view('cashier::braintree.renew_pending', [
            'subscription' => $subscription,
        ]);
    }

    /**
     * Fix transation.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function fixPayment(Request $request, $subscription_id)
    {
        // Get current customer
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        if ($request->isMethod('post')) {
            // try to renew again
            $ok = $service->renew($subscription);

            if ($ok) {
                // remove last_error
                $subscription->error = null;
                $subscription->save();
            }

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::braintree.fix_payment', [
            'subscription' => $subscription,
            'return_url' => $this->getReturnUrl($request),
            'service' => $service,
            'clientToken' => $service->serviceGateway->clientToken()->generate(),
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
        return view('cashier::braintree.payment_redirect', [
            'redirect' => $request->redirect,
        ]);
    }
}