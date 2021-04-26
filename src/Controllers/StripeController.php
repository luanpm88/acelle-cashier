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

class StripeController extends Controller
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
        return Cashier::getPaymentGateway('stripe');
    }
    
    /**
     * Subscription checkout page.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function checkout(Request $request, $invoice_uid)
    {
        $customer = $request->user()->customer;
        $service = $this->getPaymentService();
        $invoice = \Acelle\Model\Invoice::findByUid($invoice_uid);
        
        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }

        // not waiting
        if (!$invoice->isPending() && !$invoice->isClaimed()) {
            return redirect()->away($this->getReturnUrl($request));
        }

        // free plan. No charge
        if ($invoice->total() == 0) {
            $invoice->setPaid();

            return redirect()->away($this->getReturnUrl($request));
        }  

        if(!$service->hasCard($customer)) {
            // connect again
            return redirect()->away(
                $service->getConnectUrl(
                    $service->getCheckoutUrl($invoice, $this->getReturnUrl($request))
                )
            );
        }

        if ($request->isMethod('post')) {
            $result = $service->chargeInvoice($invoice);

            if ($result['status'] == 'error') {
                // reset invoice to new
                $invoice->setNew();

                // return with error message
                $request->session()->flash('alert-error', $result['error']);
                return redirect()->away($this->getReturnUrl($request));
                
            }
                
            // return 
            $result = $service->chargeInvoice($invoice);
            return redirect()->away($this->getReturnUrl($request));
        }

        return view('cashier::stripe.charging', [
            'invoice' => $invoice,
        ]);

        // // if free plan and not always required card
        // if ($subscription->plan->getBillableAmount() == 0 && $service->always_ask_for_valid_card == 'no') {
        //     // charged successfully. Set subscription to active
        //     $subscription->start();

        //     // add transaction
        //     $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
        //         'ends_at' => $subscription->ends_at,
        //         'current_period_ends_at' => $subscription->current_period_ends_at,
        //         'status' => SubscriptionTransaction::STATUS_SUCCESS,
        //         'title' => trans('cashier::messages.transaction.subscribed_to_plan', [
        //             'plan' => $subscription->plan->getBillableName(),
        //         ]),
        //         'amount' => $subscription->plan->getBillableFormattedPrice()
        //     ]);

        //     // add log
        //     $subscription->addLog(SubscriptionLog::TYPE_PAID, [
        //         'plan' => $subscription->plan->getBillableName(),
        //         'price' => $subscription->plan->getBillableFormattedPrice(),
        //     ]);

        //     // Redirect to my subscription page
        //     return redirect()->away($this->getReturnUrl($request));
        // }
        
        // // for demo site only
        // if (isSiteDemo()) {
        //     if ($subscription->plan->price == 0) {
        //         $subscription->delete();

        //         $request->session()->flash('alert-error', trans('messages.operation_not_allowed_in_demo'));
        //         return redirect()->away(Cashier::lr_action('\Acelle\Http\Controllers\AccountSubscriptionController@selectPlan'));
        //     }

        //     $service = $this->getPaymentService();
        //     \Stripe\Stripe::setApiVersion("2017-04-06");
            
        //     $session = \Stripe\Checkout\Session::create([
        //         'payment_method_types' => ['card'],
        //         'line_items' => [[
        //           'name' => $subscription->plan->getBillableName(),
        //           'description' => \Acelle\Model\Setting::get('site_name'),
        //           'images' => ['https://b.imge.to/2019/10/05/vE0yqs.png'],
        //           'amount' => $subscription->plan->stripePrice(),
        //           'currency' => $subscription->plan->getBillableCurrency(),
        //           'quantity' => 1,
        //         ]],
        //         'success_url' => Cashier::public_url('/'),
        //         'cancel_url' => \Acelle\Cashier\Cashier::lr_action('\Acelle\Http\Controllers\AccountSubscriptionController@index'),
        //     ]);
            
        //     $subscription->delete();

        //     return view('cashier::stripe.checkout_demo', [
        //         'service' => $service,
        //         'session' => $session,
        //     ]);
        // }

        // return redirect()->away(\Acelle\Cashier\Cashier::lr_action('\Acelle\Cashier\Controllers\StripeController@charge', [
        //     'subscription_id' => $subscription->uid,
        // ]));
    }
    
    // /**
    //  * Subscribe with card information.
    //  *
    //  * @param \Illuminate\Http\Request $request
    //  *
    //  * @return \Illuminate\Http\Response
    //  **/
    // public function updateCard(Request $request, $subscription_id)
    // {
    //     // subscription and service
    //     $subscription = Subscription::findByUid($subscription_id);
    //     $service = $this->getPaymentService();
        
    //     // update card
    //     $service->billableUserUpdateCard($subscription->user, $request->all());

    //     return redirect()->away($request->redirect);
    // }
    
    // /**
    //  * Subscription charge.
    //  *
    //  * @param \Illuminate\Http\Request $request
    //  *
    //  * @return \Illuminate\Http\Response
    //  **/
    // public function charge(Request $request, $subscription_id)
    // {
    //     // subscription and service
    //     $subscription = Subscription::findByUid($subscription_id);
    //     $service = $this->getPaymentService();

    //     if ($request->isMethod('post')) {
    //         // add transaction
    //         $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
    //             'ends_at' => $subscription->ends_at,
    //             'current_period_ends_at' => $subscription->current_period_ends_at,
    //             'status' => SubscriptionTransaction::STATUS_PENDING,
    //             'title' => trans('cashier::messages.transaction.subscribed_to_plan', [
    //                 'plan' => $subscription->plan->getBillableName(),
    //             ]),
    //             'amount' => $subscription->plan->getBillableFormattedPrice()
    //         ]);

    //         if ($subscription->plan->getBillableAmount() > 0) {
    //             try {
    //                 // charge customer
    //                 $service->charge($subscription, [
    //                     'amount' => $subscription->plan->getBillableAmount(),
    //                     'currency' => $subscription->plan->getBillableCurrency(),
    //                     'description' => trans('cashier::messages.transaction.subscribed_to_plan', [
    //                         'plan' => $subscription->plan->getBillableName(),
    //                     ]),
    //                 ]);
    //             } catch(\Stripe\Exception\CardException $e) {
    //                 // charged successfully. Set subscription to active
    //                 $subscription->cancelNow();

    //                 // transaction success
    //                 $transaction->description = trans('cashier::messages.charge.something_went_wrong', ['error' => $e->getError()->message]);
    //                 $transaction->setFailed();

    //                 // add log
    //                 $subscription->addLog(SubscriptionLog::TYPE_ERROR, [
    //                     'plan' => $subscription->plan->getBillableName(),
    //                     'price' => $subscription->plan->getBillableFormattedPrice(),
    //                     'message' => json_encode($e->getError()),
    //                 ]);

    //                 // Redirect to my subscription page
    //                 $request->session()->flash('alert-error', trans('cashier::messages.charge.something_went_wrong', ['error' => $e->getError()->message]));
    //                 return redirect()->away($this->getReturnUrl($request));
    //             } catch (\Exception $e) {
    //                 // charged successfully. Set subscription to active
    //                 $subscription->cancelNow();

    //                 // transaction success
    //                 $transaction->description = trans('cashier::messages.charge.something_went_wrong', ['error' => $e->getMessage()]);
    //                 $transaction->setFailed();

    //                 // add log
    //                 $subscription->addLog(SubscriptionLog::TYPE_ERROR, [
    //                     'plan' => $subscription->plan->getBillableName(),
    //                     'price' => $subscription->plan->getBillableFormattedPrice(),
    //                     'message' => $e->getMessage(),
    //                 ]);

    //                 // Redirect to my subscription page
    //                 $request->session()->flash('alert-error', trans('cashier::messages.charge.something_went_wrong', ['error' => $e->getMessage()]));
    //                 return redirect()->away($this->getReturnUrl($request));
    //             }
    //         }

    //         // charged successfully. Set subscription to active
    //         $subscription->start();

    //         // transaction success
    //         $transaction->setSuccess();

    //         // add log
    //         $subscription->addLog(SubscriptionLog::TYPE_PAID, [
    //             'plan' => $subscription->plan->getBillableName(),
    //             'price' => $subscription->plan->getBillableFormattedPrice(),
    //         ]);
    //         sleep(1);
    //         // add log
    //         $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBED, [
    //             'plan' => $subscription->plan->getBillableName(),
    //             'price' => $subscription->plan->getBillableFormattedPrice(),
    //         ]);


    //         // Redirect to my subscription page
    //         return redirect()->away($this->getReturnUrl($request));
    //     }

    //     return view('cashier::stripe.charge', [
    //         'subscription' => $subscription,
    //     ]);
    // }
    
    // /**
    //  * Change subscription plan.
    //  *
    //  * @param \Illuminate\Http\Request $request
    //  *
    //  * @return \Illuminate\Http\Response
    //  **/
    // public function changePlan(Request $request, $subscription_id)
    // {
    //     // Get current customer
    //     $subscription = Subscription::findByUid($subscription_id);
    //     $service = $this->getPaymentService();
    //     // @todo dependency injection 
    //     $newPlan = \Acelle\Model\Plan::findByUid($request->plan_id);
        
    //     // calc when change plan
    //     try {
    //         $result = Cashier::calcChangePlan($subscription, $newPlan);
    //     } catch (\Exception $e) {
    //         $request->session()->flash('alert-error', trans('cashier::messages.change_plan.failed', ['error' => $e->getMessage()]));
    //         return redirect()->away($this->getReturnUrl($request));
    //     }
        
    //     if ($request->isMethod('post')) {         
    //         // add transaction
    //         $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_PLAN_CHANGE, [
    //             'ends_at' => $subscription->ends_at,
    //             'current_period_ends_at' => $subscription->current_period_ends_at,
    //             'status' => SubscriptionTransaction::STATUS_PENDING,
    //             'title' => trans('cashier::messages.transaction.change_plan', [
    //                 'plan' => $newPlan->getBillableName(),
    //             ]),
    //             'amount' => $result['amount'],
    //         ]);

    //         // charge customer
    //         if ($result['amount'] > 0) {
    //             try {
    //                 // charge customer
    //                 $service->charge($subscription, [
    //                     'amount' => $result['amount'],
    //                     'currency' => $newPlan->getBillableCurrency(),
    //                     'description' => trans('cashier::messages.transaction.change_plan', [
    //                         'plan' => $newPlan->getBillableName(),
    //                     ]),
    //                 ]);
    //             } catch(\Stripe\Exception\CardException $e) {
    //                 // set transaction failed
    //                 $transaction->description = $e->getError()->message;
    //                 $transaction->setFailed();

    //                 // add log
    //                 $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGE_FAILED, [
    //                     'old_plan' => $subscription->plan->getBillableName(),
    //                     'plan' => $newPlan->getBillableName(),
    //                     'price' => $result['amount'],
    //                     'error' => json_encode($e->getError()),
    //                 ]);

    //                 // set subscription last_error_type
    //                 $subscription->error = json_encode([
    //                     'status' => 'error',
    //                     'type' => 'change_plan_failed',
    //                     'message' => trans('cashier::messages.change_plan_failed_with_error', [
    //                         'error' => $e->getError()->message,
    //                     ]),
    //                 ]);
    //                 $subscription->save();

    //                 // Redirect to my subscription page
    //                 return redirect()->away($this->getReturnUrl($request));
    //             } catch (\Exception $e) {
    //                 // set transaction failed
    //                 $transaction->description = $e->getMessage();
    //                 $transaction->setFailed();

    //                 // add log
    //                 $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGE_FAILED, [
    //                     'old_plan' => $subscription->plan->getBillableName(),
    //                     'plan' => $newPlan->getBillableName(),
    //                     'price' => $result['amount'],
    //                     'error' => $e->getMessage(),
    //                 ]);

    //                 // set subscription last_error_type
    //                 $subscription->error = json_encode([
    //                     'status' => 'error',
    //                     'type' => 'change_plan_failed',
    //                     'message' => trans('cashier::messages.change_plan_failed_with_error', [
    //                         'error' => $e->getMessage(),
    //                     ]),
    //                 ]);
    //                 $subscription->save();

    //                 // Redirect to my subscription page
    //                 return redirect()->away($this->getReturnUrl($request));
    //             }
    //         }
            
    //         // change plan
    //         $subscription->changePlan($newPlan);

    //         // set success
    //         $transaction->setSuccess();

    //         // add log
    //         $subscription->addLog(SubscriptionLog::TYPE_PLAN_CHANGED, [
    //             'old_plan' => $subscription->plan->getBillableName(),
    //             'plan' => $newPlan->getBillableName(),
    //             'price' => $newPlan->getBillableFormattedPrice(),
    //         ]);

    //         // remove last_error
    //         $subscription->error = null;
    //         $subscription->save();

    //         // Redirect to my subscription page
    //         return redirect()->away($this->getReturnUrl($request));
    //     }
        
    //     return view('cashier::stripe.change_plan', [
    //         'subscription' => $subscription,
    //         'return_url' => $this->getReturnUrl($request),
    //         'newPlan' => $newPlan,
    //         'nextPeriodDay' => $result['endsAt'],
    //         'service' => $service,
    //         'amount' => $result['amount'],
    //     ]);
    // }
    
    // /**
    //  * Change subscription plan pending page.
    //  *
    //  * @param \Illuminate\Http\Request $request
    //  *
    //  * @return \Illuminate\Http\Response
    //  **/
    // public function changePlanPending(Request $request, $subscription_id)
    // {
    //     // Get current customer
    //     $subscription = Subscription::findByUid($subscription_id);
    //     $service = $this->getPaymentService();
        
    //     return view('cashier::stripe.change_plan_pending', [
    //         'subscription' => $subscription,
    //         'plan_id' => $request->plan_id,
    //     ]);
    // }
    
    // /**
    //  * Payment redirecting.
    //  *
    //  * @param \Illuminate\Http\Request $request
    //  *
    //  * @return \Illuminate\Http\Response
    //  **/
    // public function paymentRedirect(Request $request)
    // {
    //     return view('cashier::stripe.payment_redirect', [
    //         'redirect' => $request->redirect,
    //     ]);
    // }
    
    // /**
    //  * Cancel new subscription.
    //  *
    //  * @param \Illuminate\Http\Request $request
    //  *
    //  * @return \Illuminate\Http\Response
    //  */
    // public function cancelNow(Request $request, $subscription_id)
    // {
    //     $subscription = Subscription::findByUid($subscription_id);
    //     $service = $this->getPaymentService();

    //     if ($subscription->isNew()) {
    //         $subscription->setEnded();
    //     }

    //     $return_url = $request->session()->get('checkout_return_url', Cashier::public_url('/'));
    //     if (!$return_url) {
    //         $return_url = Cashier::public_url('/');
    //     }

    //     // Redirect to my subscription page
    //     return redirect()->away($return_url);
    // }

    // /**
    //  * Fix transation.
    //  *
    //  * @param \Illuminate\Http\Request $request
    //  *
    //  * @return \Illuminate\Http\Response
    //  **/
    // public function fixPayment(Request $request, $subscription_id)
    // {
    //     // Get current customer
    //     $subscription = Subscription::findByUid($subscription_id);
    //     $service = $this->getPaymentService();
        
    //     if ($request->isMethod('post')) {
    //         // try to renew again
    //         $ok = $service->renew($subscription);

    //         if ($ok) {
    //             // remove last_error
    //             $subscription->error = null;
    //             $subscription->save();
    //         }

    //         // Redirect to my subscription page
    //         return redirect()->away($this->getReturnUrl($request));
    //     }
        
    //     return view('cashier::stripe.fix_payment', [
    //         'subscription' => $subscription,
    //         'return_url' => $this->getReturnUrl($request),
    //         'service' => $service,
    //     ]);
    // }

    /**
     * Connect to gateway.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     **/
    public function connect(Request $request)
    {
        // Get current customer
        $service = $this->getPaymentService();

        // Save return url
        if ($request->return_url) {
            $request->session()->put('checkout_return_url', $request->return_url);
        }

        // get card
        $card = $service->getCardInformation($request->user()->customer);
        
        if ($request->isMethod('post')) {
            if (!$request->use_current_card) {
                // update card
                $service->billableUserUpdateCard($request->user()->customer, $request->all());
            }

            // set gateway
            $card = $service->getCardInformation($request->user()->customer);
            $request->user()->customer->updatePaymentMethod([
                'method' => 'stripe',
                'user_id' => $card->name,
                'card_last4' => $card->last4,
            ]);

            // resume auto recurring if current subscription is recurring
            $subscription = $request->user()->customer->subscription;
            if (is_object($subscription) && $request->user()->customer->can('resume', $subscription)) {
                $service->resume($subscription);
            }
            
            // return to billing page
            $request->session()->flash('alert-success', trans('cashier::messages.stripe.connected'));
            return redirect()->away($this->getReturnUrl($request));
        }
        
        return view('cashier::stripe.connect', [
            'service' => $service,
            'cardInfo' => $card,
        ]);
    }

    // /**
    //  * Renew subscription.
    //  *
    //  * @param \Illuminate\Http\Request $request
    //  *
    //  * @return \Illuminate\Http\Response
    //  **/
    // public function renew(Request $request, $subscription_id)
    // {
    //     // Get current customer
    //     $subscription = Subscription::findByUid($subscription_id);
    //     $subscription->resume();
        
    //     if ($request->return_url) {
    //         return redirect()->away($request->return_url);
    //     }
    // }
}