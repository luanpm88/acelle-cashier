<?php

return [
    'gateway' => 'direct',
    'end_period_last_days' => 10,
    'renew_free_plan' => 'yes',
    'recurring_charge_before_days' => 3,
    'gateways' => [
        'direct' => [
            'name' => 'direct',
            'fields' => [
                'payment_instruction' => '',
                'confirmation_message' => '',
            ],
        ],
        'stripe' => [
            'name' => 'stripe',
            'fields' => [
                'publishable_key' => null,
                'secret_key' => null,
                'always_ask_for_valid_card' => 'no',
                'billing_address_required' => 'no',
            ],
        ],
        'braintree' => [
            'name' => 'braintree',
            'fields' => [
                'environment' => 'sandbox',
                'merchant_id' => null,
                'public_key' => null,
                'private_key' => null,
                'always_ask_for_valid_card' => 'no',
            ],
        ],
        'coinpayments' => [
            'name' => 'coinpayments',
            'fields' => [
                'merchant_id' => null,
                'public_key' => null,
                'private_key' => null,
                'ipn_secret' => null,
                'receive_currency' => 'BTC',
            ],
        ],
        'paypal' => [
            'name' => 'paypal',
            'fields' => [
                'environment' => 'sandbox',
                'client_id' => null,
                'secret' => null,
            ],
        ],
        'paypal_subscription' => [
            'name' => 'paypal_subscription',
            'fields' => [
                'environment' => 'sandbox',
                'client_id' => null,
                'secret' => null,
            ],
        ],
        'razorpay' => [
            'name' => 'razorpay',
            'fields' => [                
                'key_id' => null,
                'key_secret' => null,
            ],
        ],
    ],
];