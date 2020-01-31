<?php

namespace Acelle\Cashier;

use Illuminate\Support\ServiceProvider;

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
                return new \Acelle\Cashier\Services\DirectPaymentGateway($config['fields']['payment_instruction'], $config['fields']['confirmation_message']);
            case 'stripe':
                return new \Acelle\Cashier\Services\StripePaymentGateway($config['fields']['secret_key'], $config['fields']['publishable_key']);
            case 'braintree':
                return new \Acelle\Cashier\Services\BraintreePaymentGateway(
                    $config['fields']['environment'],
                    $config['fields']['merchant_id'],
                    $config['fields']['public_key'],
                    $config['fields']['private_key']
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
            default:
                return false;
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
        
        $newEndsAt = $subscription->current_period_ends_at;
        
        $remainDays = $subscription->current_period_ends_at->diffInDays(\Carbon\Carbon::now());

        // amout per day of current plan
        $currentAmount = $subscription->plan->getBillableAmount();
        $periodDays = $subscription->current_period_ends_at->diffInDays($subscription->periodStartAt());
        $remainDays = $subscription->current_period_ends_at->diffInDays(\Carbon\Carbon::now());
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
            'amount' => $amount,
            'endsAt' => $newEndsAt,
        ];
    }
}