<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;
use Acelle\Cashier\Services\StripePaymentGateway;

class PaystackController extends Controller
{
    public function getReturnUrl(Request $request) {
        $return_url = $request->session()->get('checkout_return_url', Cashier::public_url('/'));
        if (!$return_url) {
            $return_url = Cashier::public_url('/');
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
        return Cashier::getPaymentGateway('paystack');
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
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }

        // if subscription is active
        if ($subscription->isActive() || $subscription->isEnded()) {
            return redirect()->away($this->getReturnUrl($request));
        }

        // verify payment
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

            try {
                $service->verifyPayment($subscription, $request->reference);
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

                $request->session()->flash('alert-error', trans('cashier::messages.charge.something_went_wrong', ['error' => $e->getMessage()]));
                return redirect()->away($this->getReturnUrl($request));
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

        // if free plan and not always required card
        if ($subscription->plan->getBillableAmount() == 0) {
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

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }

        return view('cashier::paystack.checkout', [
            'service' => $this->getPaymentService(),
            'subscription' => $subscription,
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
        return view('cashier::paystack.payment_redirect', [
            'redirect' => $request->redirect,
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
        $service = $this->getPaymentService();
        
        // update card
        $service->billableUserUpdateCard($subscription->user, $request->all());

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
                } catch(\Stripe\Exception\CardException $e) {
                    // charged successfully. Set subscription to active
                    $subscription->cancelNow();

                    // transaction success
                    $transaction->description = trans('cashier::messages.charge.something_went_wrong', ['error' => $e->getError()->message]);
                    $transaction->setFailed();

                    // add log
                    $subscription->addLog(SubscriptionLog::TYPE_ERROR, [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                        'message' => json_encode($e->getError()),
                    ]);

                    // Redirect to my subscription page
                    $request->session()->flash('alert-error', trans('cashier::messages.charge.something_went_wrong', ['error' => $e->getError()->message]));
                    return redirect()->away($this->getReturnUrl($request));
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
        $newPlan = \Acelle\Model\Plan::findByUid($request->plan_id);
        
        // calc when change plan
        try {            
            $result = Cashier::calcChangePlan($subscription, $newPlan);
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
                    'plan' => $newPlan->getBillableName(),
                ]),
                'amount' => $result['amount'],
            ]);
            
            // charge customer
            if ($result['amount'] > 0) {
                try {
                    // use old card
                    if ($request->use_old_card) {
                        $service->charge($subscription, [
                            'amount' => $result['amount'],
                            'currency' => $newPlan->getBillableCurrency(),
                        ]);
                    // new card
                    } else {
                        $service->verifyPayment($subscription, $request->reference);
                    }
                } catch (\Exception $e) {
                    // set transaction failed
                    $transaction->description = $e->getMessage();
                    $transaction->setFailed();

                    // add log
                    $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGE_FAILED, [
                        'old_plan' => $subscription->plan->getBillableName(),
                        'plan' => $newPlan->getBillableName(),
                        'price' => $result['amount'],
                        'error' => json_encode($e->getMessage()),
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
            $subscription->changePlan($newPlan);

            // set success
            $transaction->setSuccess();

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGED, [
                'old_plan' => $subscription->plan->getBillableName(),
                'plan' => $newPlan->getBillableName(),
                'price' => $newPlan->getBillableFormattedPrice(),
            ]);

            // remove last_error
            $subscription->error = null;
            $subscription->save();

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::paystack.change_plan', [
            'subscription' => $subscription,
            'return_url' => $this->getReturnUrl($request),
            'newPlan' => $newPlan,
            'nextPeriodDay' => $result['endsAt'],
            'service' => $service,
            'amount' => $result['amount'],
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
        
        return view('cashier::paystack.renew', [
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

        // Redirect to my subscription page
        return redirect()->away($this->getReturnUrl($request));
    }

    /**
     * Fix transation.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function renew(Request $request, $subscription_id)
    {
        // init
        $subscription = Subscription::findByUid($subscription_id);
        $service = $this->getPaymentService();
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }

        // if subscription is recurring
        if (!$subscription->isRecurring() || !$subscription->isExpiring()) {
            $request->session()->flash('alert-error', 'Subscription must be recurring and expiring!');
            return redirect()->away($this->getReturnUrl($request));
        }
        
        if ($request->isMethod('post')) {
            // add transaction
            $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_AUTO_CHARGE, [
                'ends_at' => null,
                'current_period_ends_at' => $subscription->nextPeriod(),
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.recurring_charge', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            try {
                $service->verifyPayment($subscription, $request->reference);
            } catch (\Exception $e) {
                $transaction->setFailed();

                // update error message
                $transaction->description = $e->getMessage();
                $transaction->save();

                // set subscription last_error_type
                $subscription->error = json_encode([
                    'status' => 'error',
                    'type' => 'renew',
                    'message' => trans('cashier::messages.renew.error', [
                        'date' => $subscription->current_period_ends_at,
                        'error' => $e->getMessage(),
                    ]),
                ]);
                $subscription->save();

                // add log
                $subscription->addLog(SubscriptionLog::TYPE_RENEW_FAILED, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                    'error' => $e->getMessage(),
                ]);

                $request->session()->flash('alert-error', trans('cashier::messages.charge.something_went_wrong', ['error' => $e->getMessage()]));
                return redirect()->away($this->getReturnUrl($request));
            }

            // set active
            $transaction->setSuccess();

            // check new states from transaction
            $subscription->ends_at = $transaction->ends_at;
            // save last period
            $subscription->last_period_ends_at = $subscription->current_period_ends_at;
            // set new current period
            $subscription->current_period_ends_at = $transaction->current_period_ends_at;
            $subscription->save();

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_RENEWED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            // remove last_error
            $subscription->error = null;
            $subscription->save();

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::paystack.renew', [
            'service' => $this->getPaymentService(),
            'subscription' => $subscription,
        ]);
    }
}