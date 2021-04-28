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
    public static function wp_action($name, $parameters = [], $absolute = true)
    {
        if (defined('WORDPRESS_MODE')) {
            return wp_action($name, $parameters, $absolute);
        } else {
            return action($name, $parameters, $absolute);
        }
    }
    public static function lr_action($name, $parameters = [], $absolute = true)
    {
        if (defined('WORDPRESS_MODE')) {
            return lr_action($name, $parameters, $absolute);
        } else {
            return action($name, $parameters, $absolute);
        }
    }
    public static function public_url($path)
    {
        if (defined('WORDPRESS_MODE')) {
            return public_url($path);
        } else {
            return url($path);
        }
    }

    /**
     * Get payment gateway.
     *
     * @var array
     */
    public static function getPaymentGateway($name, $fields=null)
    {
        $config = config('cashier.gateways.' . $name);
        
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
                    $config['fields']['ipn_secret'],
                    $config['fields']['receive_currency'],
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
            case 'paystack':
                return new \Acelle\Cashier\Services\PaystackPaymentGateway(
                    $config['fields']['public_key'],
                    $config['fields']['secret_key']
                );
            default:
                throw new \Exception("Can not find payment service: " . $config['name']);
        }
    }
}