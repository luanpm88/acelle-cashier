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
            ],
        ],
        'braintree' => [
            'name' => 'braintree',
            'fields' => [
                'environment' => 'sandbox',
                'merchant_id' => null,
                'public_key' => null,
                'private_key' => null,
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
    ],
];