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
                return new \Acelle\Cashier\Services\DirectPaymentGateway($config['fields']['notice']);
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
            default:
                return false;
        }
    }
}