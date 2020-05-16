<?php

namespace Acelle\Cashier\Controllers;

use Acelle\Http\Controllers\Controller;
use Acelle\Cashier\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as LaravelLog;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Services\CoinpaymentsPaymentGateway;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;

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
        if ($subscription->isActive() || $subscription->isEnded()) {
            return redirect()->away($this->getReturnUrl($request));
        }
        
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
                // add remote transaction
                $result = $gatewayService->charge($subscription, [
                    'id' => $transaction->uid,
                    'amount' => $subscription->plan->getBillableAmount(),
                    'desc' => trans('cashier::messages.coinpayments.subscribe_to_plan', [
                        'plan' => $subscription->plan->getBillableName(),
                    ]),                
                ]);
            } catch (\Exception $e) {
                // set transaction failed
                $transaction->description = $e->getMessage();
                $transaction->setFailed();

                // add log
                sleep(1);
                $subscription->addLog(SubscriptionLog::TYPE_ERROR, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableAmount(),
                    'message' => $e->getMessage(),
                ]);

                // cancel now
                $subscription->cancelNow();

                // Redirect to my subscription page
                $request->session()->flash('alert-error', 'Can not create transaction: ' . $e->getMessage());
                return redirect()->away($this->getReturnUrl($request));
            }

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
        
        if ($request->isMethod('post')) {
            // add transaction
            $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_RENEW, [
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
                try {
                    $result = $service->charge($subscription, [
                        'id' => $transaction->uid,
                        'amount' => $subscription->plan->getBillableAmount(),
                        'desc' => trans('cashier::messages.transaction.renew_plan', [
                            'plan' => $subscription->plan->getBillableName(),
                        ]),                
                    ]);
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
                            'link' => action('\Acelle\Cashier\Controllers\CoinpaymentsController@renew', [
                                'subscription_id' => $subscription->uid,
                                'return_url' => action('AccountSubscriptionController@index'),
                            ]),
                        ]),
                    ]);
                    $subscription->save();

                    // Redirect to my subscription page
                    return redirect()->away($this->getReturnUrl($request));
                }                    

                // update remote data
                $transaction->updateMetadata([
                    'txn_id' => $result["txn_id"],
                    'checkout_url' => $result["checkout_url"],
                    'status_url' => $result["status_url"],
                    'qrcode_url' => $result["qrcode_url"],
                ]);

                // add error notice
                $subscription->error = json_encode([
                    'status' => 'warning',
                    'type' => 'renew',
                    'message' => trans('cashier::messages.renew_pending', [
                        'plan' => $subscription->plan->getBillableName(),
                        'amount' => $transaction->amount,
                        'url' => action('\Acelle\Cashier\Controllers\CoinpaymentsController@transactionPending', [
                            'subscription_id' => $subscription->uid,
                        ]),
                    ]),
                ]);
                $subscription->save();
            } elseif ($subscription->plan->getBillableAmount() == 0) {
                $service->approvePending($subscription);
                return redirect()->away($this->getReturnUrl($request));

                // add log
                $subscription->addLog(SubscriptionLog::TYPE_RENEWED, [
                    'plan' => $subscription->plan->getBillableName(),
                    'price' => $subscription->plan->getBillableFormattedPrice(),
                ]);
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

        // calc plan before change
        try {
            $result = Cashier::calcChangePlan($subscription, $plan);
        } catch (\Exception $e) {
            $request->session()->flash('alert-error', trans('cashier::messages.change_plan.failed', ['error' => $e->getMessage()]));
            return redirect()->away($this->getReturnUrl($request));
        }


        $plan->price = $result['amount'];
        if ($request->isMethod('post')) {
            // add transaction
            $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_PLAN_CHANGE, [
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
                // add remote transaction
                try {
                    $result = $service->charge($subscription, [
                        'id' => $transaction->uid,
                        'amount' => $result['amount'],
                        'desc' => trans('cashier::messages.transaction.change_plan', [
                            'plan' => $plan->getBillableName(),
                        ]),                
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
                                'link' => action("\Acelle\Cashier\Controllers\\CoinpaymentsController@renew", [
                                    'subscription_id' => $subscription->uid,
                                    'return_url' => action('AccountSubscriptionController@index'),
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

                // update remote data
                $transaction->updateMetadata([
                    'txn_id' => $result["txn_id"],
                    'checkout_url' => $result["checkout_url"],
                    'status_url' => $result["status_url"],
                    'qrcode_url' => $result["qrcode_url"],
                ]);

                // add error notice
                $subscription->error = json_encode([
                    'status' => 'warning',
                    'type' => 'change_plan',
                    'message' => trans('cashier::messages.change_plan_pending', [
                        'plan' => $plan->getBillableName(),
                        'amount' => $transaction->amount,
                        'url' => action('\Acelle\Cashier\Controllers\CoinpaymentsController@transactionPending', [
                            'subscription_id' => $subscription->uid,
                        ]),
                    ]),
                ]);
                $subscription->save();
            } elseif (round($result['amount']) == 0) {
                $service->approvePending($subscription);

                // add log
                $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGED, [
                    'old_plan' => $subscription->plan->getBillableName(),
                    'plan' => $plan->getBillableName(),
                    'price' => $plan->getBillableFormattedPrice(),
                ]);

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