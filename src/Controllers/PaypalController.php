<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;
use Acelle\Cashier\Services\PaypalPaymentGateway;

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
        $subscription = Subscription::findByUid($subscription_id);

        // return url
        $return_url = $request->session()->get('checkout_return_url', Cashier::public_url('/'));
        if (!$return_url) {
            $return_url = Cashier::public_url('/');
        }
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }
        
        // if subscription is active
        if ($subscription->isActive() || $subscription->isEnded()) {
            return redirect()->away($this->getReturnUrl($request));
        }

        if ($request->isMethod('post')) {
            // add transaction
            $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_SUCCESS,
                'title' => trans('cashier::messages.transaction.subscribed_to_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice()
            ]);

            // throw excaption of failed
            if ($subscription->plan->price > 0) {
                try {
                    $service->charge($subscription, [
                        'orderID' => $request->orderID,
                    ]);

                    sleep(1);
                    // add log
                    $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
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
            $subscription->setActive();

            // transaction success
            $transaction->setSuccess();

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
            
            sleep(1);
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
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

        // return url
        $return_url = $request->session()->get('checkout_return_url', Cashier::public_url('/'));
        if (!$return_url) {
            $return_url = Cashier::public_url('/');
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
                    'old_plan' => $subscription->plan->getBillableName(),
                    'plan' => $plan->getBillableName(),
                ]),
                'amount' => $result['amount']
            ]);

            // charge
            if (round($result['amount']) > 0) {
                try {
                    // charge customer
                    $service->charge($subscription, [
                        'orderID' => $request->orderID,
                    ]);
                } catch (\Exception $e) {
                    // set transaction failed
                    $transaction->description = $e->getMessage();
                    $transaction->setFailed();
                    
                    
                    // add log
                    sleep(1);
                    $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGE_FAILED, [
                        'old_plan' => $subscription->plan->getBillableName(),
                        'plan' => $plan->getBillableName(),
                        'price' => $result['amount'],
                        'error' => $e->getMessage(),
                    ]);

                    // set subscription last_error_type
                    if ($subscription->isExpiring() && $subscription->canRenewPlan()) {
                        $subscription->error = json_encode([
                            'status' => 'error',
                            'type' => 'change_plan_failed',                    
                            'message' => trans('cashier::messages.change_plan_failed_with_renew', [
                                'error' => $e->getMessage(),
                                'date' => $subscription->current_period_ends_at,
                                'link' => \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\PaypalController@renew", [
                                    'subscription_id' => $subscription->uid,
                                    'return_url' => \Acelle\Cashier\Cashier::lr_action('AccountSubscriptionController@index'),
                                ]),
                            ]),
                        ]);
                    } else {
                        $subscription->error = json_encode([
                            'status' => 'error',
                            'type' => 'change_plan_failed',
                            'message' => trans('cashier::messages.change_plan_failed_with_error', [
                                'error' => $e->getMessage(),
                            ]),
                        ]);
                    }
                    $subscription->save();

                    // Redirect to my subscription page
                    return redirect()->away($this->getReturnUrl($request));
                }                

                sleep(1);
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                    'plan' => $plan->getBillableName(),
                    'price' => $plan->getBillableFormattedPrice(),
                ]);
            }
            
            // change plan
            $subscription->changePlan($plan, round($result['amount']));
            
            // set success
            $transaction->setSuccess();

            sleep(1);
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGED, [
                'plan' => $plan->getBillableName(),
                'price' => $plan->getBillableFormattedPrice(),
            ]);

            // remove last_error
            $subscription->error = null;
            $subscription->save();

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }

        $plan->price = round($result['amount']);
        
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

        // return url
        $return_url = $request->session()->get('checkout_return_url', Cashier::public_url('/'));
        if (!$return_url) {
            $return_url = Cashier::public_url('/');
        }
        
        if ($request->isMethod('post')) {
            // add transaction
            $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_RENEW, [
                'ends_at' => $subscription->ends_at,
                'current_period_ends_at' => $subscription->current_period_ends_at,
                'status' => SubscriptionTransaction::STATUS_PENDING,
                'title' => trans('cashier::messages.transaction.renew_plan', [
                    'plan' => $subscription->plan->getBillableName(),
                ]),
                'amount' => $subscription->plan->getBillableFormattedPrice()
            ]);

            if ($subscription->plan->price > 0) {
                try {
                    // check order ID
                    $service->checkOrderId($request->orderID);
                } catch (\Exception $e) {
                    // set transaction failed
                    $transaction->description = $e->getMessage();
                    $transaction->setFailed();                    
                    
                    // add log
                    sleep(1);
                    $subscription->addLog(SubscriptionLog::TYPE_RENEW_FAILED, [
                        'plan' => $subscription->plan->getBillableName(),
                        'price' => $subscription->plan->getBillableFormattedPrice(),
                        'error' => $e->getMessage(),
                    ]);

                    // set subscription last_error_type
                    $subscription->error = json_encode([
                        'status' => 'warning',
                        'type' => 'renew_failed',
                        'message' => trans('cashier::messages.renew_failed_with_error', [
                            'error' => $e->getMessage(),
                            'link' => \Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\PaypalController@renew', [
                                'subscription_id' => $subscription->uid,
                                'return_url' => \Acelle\Cashier\Cashier::lr_action('AccountSubscriptionController@index'),
                            ]),
                        ]),
                    ]);
                    $subscription->save();

                    // Redirect to my subscription page
                    return redirect()->away($this->getReturnUrl($request));
                }                
                
                // add log
                $subscription->addLog(SubscriptionLog::TYPE_PAID, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
            }
            
            // renew
            $subscription->renew();

            // set success
            $transaction->setSuccess();
            
            sleep(1);
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_RENEWED, [
                'old_plan' => $subscription->plan->getBillableName(),
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);

            // remove last_error
            $subscription->error = null;
            $subscription->save();

            // Redirect to my subscription page
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::paypal.renew', [
            'service' => $service,
            'subscription' => $subscription,
            'return_url' => $request->return_url,
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
        return view('cashier::paypal.payment_redirect', [
            'redirect' => $request->redirect,
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

        if ($subscription->isPending() || $subscription->isNew()) {
            $subscription->cancelNow();

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