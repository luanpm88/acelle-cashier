<?php

namespace Acelle\Cashier\Services;

use Stripe\Card as StripeCard;
use Stripe\Token as StripeToken;
use Stripe\Customer as StripeCustomer;
use Stripe\Subscription as StripeSubscription;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Cashier\Subscription;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;
use Acelle\Cashier\InvoiceParam;
use Carbon\Carbon;

class DirectPaymentGateway implements PaymentGatewayInterface {
    const ERROR_PENDING_REJECTED = 'pending-rejected';

    public $payment_instruction;
    public $confirmation_message;

    public function __construct($payment_instruction, $confirmation_message)
    {
        $this->payment_instruction = $payment_instruction;
        $this->confirmation_message = $confirmation_message;

        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    /**
     * Gateway check method.
     *
     * @return void
     */
    public function check($subscription)
    {
        // check expired
        if ($subscription->isExpired()) {
            $subscription->cancelNow();

            // add log
            $subscription->addLog(SubscriptionLog::TYPE_EXPIRED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);
        }
        
        if (!$subscription->hasError()) {
            // check renew pending
            if ($subscription->isExpiring() && $subscription->canRenewPlan()) {
                $subscription->error = json_encode([
                    'status' => 'warning',
                    'type' => 'renew',
                    'message' => trans('cashier::messages.renew.warning', [
                        'date' => $subscription->current_period_ends_at,
                        'link' => \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\DirectController@renew", [
                            'subscription_id' => $subscription->uid,
                            'return_url' => \Acelle\Cashier\Cashier::lr_action('AccountSubscriptionController@index'),
                        ]),
                    ]),
                ]);
                $subscription->save();
            }
        }
    }

    /**
     * Get payment guiline message.
     *
     * @return Boolean
     */
    public function getPaymentInstruction()
    {
        if (config('cashier.gateways.direct.fields.payment_instruction')) {
            return config('cashier.gateways.direct.fields.payment_instruction');
        } else {
            return trans('cashier::messages.direct.payment_instruction.demo');
        }
            
    }

    /**
     * Get payment confirmation message.
     *
     * @return Boolean
     */
    public function getPaymentConfirmationMessage()
    {
        if (config('cashier.gateways.direct.fields.confirmation_message')) {
            return config('cashier.gateways.direct.fields.confirmation_message');
        } else {
            return trans('cashier::messages.direct.confirmation_message.demo');
        }
            
    }

    /**
     * Create subscription.
     *
     * @return Subscription
     */
    public function create($customer, $plan) {
        // update subscription model
        if ($customer->subscription) {
            $subscription = $customer->subscription;
        } else {
            $subscription = new Subscription();
            $subscription->user_id = $customer->getBillableId();
        } 
        // @todo when is exactly started at?
        $subscription->started_at = \Carbon\Carbon::now();
        
        $subscription->plan_id = $plan->getBillableId();
        $subscription->status = Subscription::STATUS_NEW;

        // set gateway
        $subscription->gateway = 'direct';
        
        // set dates and save
        $subscription->ends_at = $subscription->getPeriodEndsAt(Carbon::now());
        $subscription->current_period_ends_at = $subscription->ends_at;
        $subscription->save();

        // subscription transaction
        $transaction = $subscription->addTransaction(SubscriptionTransaction::TYPE_SUBSCRIBE, [
            'ends_at' => $subscription->ends_at,
            'current_period_ends_at' => $subscription->current_period_ends_at,
            'status' => SubscriptionTransaction::STATUS_PENDING,
            'title' => trans('cashier::messages.transaction.subscribed_to_plan', [
                'plan' => $subscription->plan->getBillableName(),
            ]),
            'amount' => $subscription->plan->getBillableFormattedPrice(),
            'description' => trans('cashier::messages.direct.payment_is_not_claimed'),
        ]);
        
        // If plan is free: enable subscription & update transaction
        if ($plan->getBillableAmount() == 0) {
            $subscription->setActive();

            // set transaction
            $this->claim($transaction);
            $transaction->setSuccess();
            
            sleep(1);
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBED, [
                'plan' => $plan->getBillableName(),
                'price' => $plan->getBillableFormattedPrice(),
            ]);
        }       
        
        return $subscription;
    }

    /**
     * Get first/init transaction
     *
     * @return boolean
     */
    public function getInitTransaction($subscription) {
        return $subscription->subscriptionTransactions()
            ->where('type', '=', SubscriptionTransaction::TYPE_SUBSCRIBE)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Get last transaction
     *
     * @return boolean
     */
    public function getLastTransaction($subscription) {
        return $subscription->subscriptionTransactions()->orderBy('created_at', 'desc')->first();
    }

    /**
     * Check if transaction is claimed.
     *
     * @return boolean
     */
    public function isClaimed($transaction) {
        $data = $transaction->getMetadata();

        if (isset($data['payment_claimed']) && $data['payment_claimed']) {
            return true;
        }

        return false;
    }

    /**
     * Claim payment.
     *
     * @return void
     */
    public function claim($transaction)
    {
        $data = $transaction->getMetadata();
        $data['payment_claimed'] = true;
        $transaction->updateMetadata($data);

        // update description
        $transaction->description = trans('cashier::messages.direct.payment_was_claimed');
        $transaction->save();
    }

    /**
     * Unclaim payment.
     *
     * @return void
     */
    public function unclaim($transaction)
    {
        $data = $transaction->getMetadata();
        $data['payment_claimed'] = false;
        $transaction->updateMetadata($data);

        // update description
        $transaction->description = trans('cashier::messages.direct.payment_is_not_claimed');
        $transaction->save();        
    }
    
    public function validate() {
        return true;
    }

    /**
     * Service does not support auto recurring.
     *
     * @return boolean
     */
    public function isSupportRecurring() {
        return false;
    }

    /**
     * Set subscription active if it is pending.
     *
     * @return boolean
     */
    public function setActive($subscription) {
        $transaction = $this->getInitTransaction($subscription);
        $transaction->setSuccess();

        // set active subscription
        $subscription->setActive();

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_ADMIN_APPROVED, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
        sleep(1);
        // add log
        $subscription->addLog(SubscriptionLog::TYPE_SUBSCRIBED, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
    }

    /**
     * Approve renew/change plan pending.
     *
     * @return boolean
     */
    public function approvePending($subscription) {
        $transaction = $this->getLastTransaction($subscription);
        $transaction->setSuccess();

        // check new states
        $subscription->ends_at = $transaction->ends_at;

        // period date update
        if ($subscription->current_period_ends_at != $transaction->current_period_ends_at) {
            // save last period
            $subscription->last_period_ends_at = $subscription->current_period_ends_at;
            // set new current period
            $subscription->current_period_ends_at = $transaction->current_period_ends_at;
        }

        // check new plan
        $data = $transaction->getMetadata();
        if (isset($data['plan_id'])) {
            $subscription->plan_id = $data['plan_id'];
        }

        $subscription->save();

        // log
        if ($transaction->type == SubscriptionTransaction::TYPE_RENEW) {
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_RENEW_APPROVED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
            ]);
        } else {
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_CHANGE_APPROVED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $transaction->amount,
            ]);
        }

        // clear error
        $subscription->error = null;
        $subscription->save();
    }

    /**
     * Reject renew/change plan pending.
     *
     * @return boolean
     */
    public function rejectPending($subscription, $reason) {
        $transaction = $this->getLastTransaction($subscription);
        $transaction->setFailed();

        // log
        if ($transaction->type == SubscriptionTransaction::TYPE_RENEW) {
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_RENEW_REJECTED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $subscription->plan->getBillableFormattedPrice(),
                'reason' => $reason,
            ]);

            // add error notice
            $subscription->error = json_encode([
                'status' => 'error',
                'type' => 'renew_rejected',
                'message' => trans('cashier::messages.rejected', [
                    'reason' => $reason,
                    'date' => $subscription->current_period_ends_at,
                    'link' => \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\DirectController@renew", [
                        'subscription_id' => $subscription->uid,
                        'return_url' => \Acelle\Cashier\Cashier::lr_action('AccountSubscriptionController@index'),
                    ]),
                ]),
            ]);
            $subscription->save();
        } else {
            // add log
            $subscription->addLog(SubscriptionLog::TYPE_ADMIN_PLAN_CHANGE_REJECTED, [
                'plan' => $subscription->plan->getBillableName(),
                'price' => $transaction->amount,
                'reason' => $reason,
            ]);

            // add error notice
            if ($subscription->isExpiring() && $subscription->canRenewPlan()) {
                $subscription->error = json_encode([
                    'status' => 'error',
                    'type' => 'change_plan_rejected',                    
                    'message' => trans('cashier::messages.change_plan_rejected_with_renew', [
                        'reason' => $reason,
                        'date' => $subscription->current_period_ends_at,
                        'link' => \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\DirectController@renew", [
                            'subscription_id' => $subscription->uid,
                            'return_url' => \Acelle\Cashier\Cashier::lr_action('AccountSubscriptionController@index'),
                        ]),
                    ]),
                ]);
            } else {
                $subscription->error = json_encode([
                    'status' => 'error',
                    'type' => 'change_plan_rejected',
                    'message' => trans('cashier::messages.change_plan_rejected', [
                        'reason' => $reason,
                    ]),
                ]);
            }
            $subscription->save();
        }

        // save reason
        $data = $transaction->getMetadata();
        $data['reject-reason'] = $reason;
        $transaction->updateMetadata($data);
    }

    public function sync($subscription) {}

    /**
     * Get renew url.
     *
     * @return string
     */
    public function getPendingUrl($subscription, $returnUrl='/')
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\DirectController@pending", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }

    /**
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($subscription, $returnUrl='/') {
        return \Acelle\Cashier\Cashier::wp_action("\Acelle\Cashier\Controllers\DirectController@checkout", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }
    
    /**
     * Get change plan url.
     *
     * @return string
     */
    public function getChangePlanUrl($subscription, $plan_id, $returnUrl='/')
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\DirectController@changePlan", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
            'plan_id' => $plan_id,
        ]);
    }

    /**
     * Get renew url.
     *
     * @return string
     */
    public function getRenewUrl($subscription, $returnUrl='/')
    {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\\DirectController@renew", [
            'subscription_id' => $subscription->uid,
            'return_url' => $returnUrl,
        ]);
    }

    // public function hasError($subscription) {
    //     $error_type = $subscription->last_error_type;

    //     switch ($error_type) {
    //         case DirectPaymentGateway::ERROR_PENDING_REJECTED:
    //             $transaction = $this->getLastTransaction($subscription);

    //             return $transaction->isFailed();
    //         default:
    //             return false;
    //     }
    // }
    // public function getErrorNotice($subscription) {
    //     $error_type = $subscription->last_error_type;

    //     switch ($error_type) {
    //         case DirectPaymentGateway::ERROR_PENDING_REJECTED:
    //             $transaction = $this->getLastTransaction($subscription);
    //             $reason = isset($transaction->getMetadata()['reject-reason']) ? $transaction->getMetadata()['reject-reason'] : '';
    //             return trans('cashier::messages.last_payment_failed', ['reason' => $reason]);
    //         default:
    //             return '';
    //     }
    // }

    /**
     * Cancel subscription.
     *
     * @return string
     */
    public function cancel($subscription) {
        $subscription->cancel();

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_CANCELLED, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
    }

    /**
     * Cancel now subscription.
     *
     * @return string
     */
    public function cancelNow($subscription) {
        $subscription->cancelNow();

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_CANCELLED_NOW, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
    }

    /**
     * Resume now subscription.
     *
     * @return string
     */
    public function resume($subscription) {
        $subscription->resume();

        // add log
        $subscription->addLog(SubscriptionLog::TYPE_RESUMED, [
            'plan' => $subscription->plan->getBillableName(),
            'price' => $subscription->plan->getBillableFormattedPrice(),
        ]);
    }

    /**
     * Check if use remote subscription.
     *
     * @return void
     */
    public function useRemoteSubscription()
    {
        return false;
    }
}