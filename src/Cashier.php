<?php

namespace Acelle\Cashier;

use Illuminate\Support\ServiceProvider;
use Acelle\Cashier\Subscription;
use Acelle\Cashier\SubscriptionTransaction;
use Acelle\Cashier\SubscriptionLog;

class Cashier
{
    
    /**
     * Get payment gateway.
     *
     * @var array
     */
    public static function getPaymentGateway($name=null, $fields=null)
    {
        if (isset($name)) {
            $config = config('cashier.gateways.' . $name);
        } else {
            $config = config('cashier.gateways.' . config('cashier.gateway'));
        }
        
        // overide fields
        if (isset($fields)) {
            $config['fields'] = $fields;
        }
        
        switch ($config['name']) {
            case 'direct':
                return new \Acelle\Cashier\Services\DirectPaymentGateway(
                    $config['fields']['payment_instruction'],
                    $config['fields']['confirmation_message']
                );
            case 'stripe':
                return new \Acelle\Cashier\Services\StripePaymentGateway(
                    $config['fields']['secret_key'],
                    $config['fields']['publishable_key'],
                    $config['fields']['always_ask_for_valid_card'],
                    $config['fields']['billing_address_required']
                );
            case 'braintree':
                return new \Acelle\Cashier\Services\BraintreePaymentGateway(
                    $config['fields']['environment'],
                    $config['fields']['merchant_id'],
                    $config['fields']['public_key'],
                    $config['fields']['private_key'],
                    $config['fields']['always_ask_for_valid_card']
                );
            case 'coinpayments':
                return new \Acelle\Cashier\Services\CoinpaymentsPaymentGateway(
                    $config['fields']['merchant_id'],
                    $config['fields']['public_key'],
                    $config['fields']['private_key'],
                    $config['fields']['ipn_secret']
                );
            case 'paypal':
                return new \Acelle\Cashier\Services\PaypalPaymentGateway(
                    $config['fields']['environment'],
                    $config['fields']['client_id'],
                    $config['fields']['secret']
                );
            case 'paypal_subscription':
                return new \Acelle\Cashier\Services\PaypalSubscriptionPaymentGateway(
                    $config['fields']['environment'],
                    $config['fields']['client_id'],
                    $config['fields']['secret']
                );
            case 'razorpay':
                return new \Acelle\Cashier\Services\RazorpayPaymentGateway(
                    $config['fields']['key_id'],
                    $config['fields']['key_secret']
                );
            default:
                throw new \Exception("Can not find payment service: " . $config['name']);
        }
    }
    
    /**
     * user want to change plan.
     *
     * @return bollean
     */
    public static function calcChangePlan($subscription, $plan)
    {
        if (($subscription->plan->getBillableInterval() != $plan->getBillableInterval()) ||
            ($subscription->plan->getBillableIntervalCount() != $plan->getBillableIntervalCount()) ||
            ($subscription->plan->getBillableCurrency() != $plan->getBillableCurrency())
        ) {
            throw new \Exception(trans('cashier::messages.can_not_change_to_diff_currency_period_plan'));
        }
        
        // new ends at
        $newEndsAt = $subscription->current_period_ends_at;

        // amout per day of current plan
        $currentAmount = $subscription->plan->getBillableAmount();
        $periodDays = $subscription->current_period_ends_at->diffInDays($subscription->periodStartAt()->startOfDay());
        $remainDays = $subscription->current_period_ends_at->diffInDays(\Carbon\Carbon::now()->startOfDay());
        $currentPerDayAmount = ($currentAmount/$periodDays);
        $newAmount = ($plan->price/$periodDays)*$remainDays;
        $remainAmount = $currentPerDayAmount*$remainDays;

        $amount = $newAmount - $remainAmount;
        
        // if amount < 0
        if ($amount < 0) {
            $days = (int) ceil(-($amount/$currentPerDayAmount));
            $amount = 0;
            $newEndsAt->addDays($days);
            
            // if free plan
            if ($plan->getBillableAmount() == 0) {
                $newEndsAt = $subscription->current_period_ends_at;
            }
        }

        return [
            'amount' => round($amount, 2),
            'endsAt' => $newEndsAt,
        ];
    }
}