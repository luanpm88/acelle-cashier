<?php

return [
    'gateway' => 'direct',
    'gateways' => [
        'direct' => [
            'name' => 'direct',
            'fields' => [
                'notice' => 'How to pay plan guide....',
            ],
        ],
        'stripe' => [
            'name' => 'stripe',
            'fields' => [
                'publishable_key' => null,
                'secret_key' => null,
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
    ],
];