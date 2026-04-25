<?php

/*
|--------------------------------------------------------------------------
| Cashier routes
|--------------------------------------------------------------------------
|
| Stripe + StripeSubscription use the new PaymentIntent flow ({intent_uid}).
| Offline still uses the legacy {invoice_uid} flow.
|
| Security model: intent_uid is a capability token (UUIDv4 = 122 bits entropy).
| Pay endpoints are throttled to limit abuse if a uid leaks.
|
*/

Route::group(['middleware' => ['web', 'not_installed'], 'namespace' => 'App\Cashier\Controllers'], function () {
    // Offline (legacy invoice_uid flow)
    Route::get('/cashier/offline/checkout/{invoice_uid}', 'OfflineController@checkout');
    Route::post('/cashier/offline/{invoice_uid}//{payment_gateway_id}/claim', 'OfflineController@claim');

    // Stripe (one-off, intent-based)
    Route::get('/cashier/stripe/checkout/{intent_uid}', 'StripeController@checkout');
    Route::post('/cashier/stripe/pay/{intent_uid}', 'StripeController@pay')
        ->middleware('throttle:10,1'); // 10 pay attempts per minute per IP
    Route::get('/cashier/stripe/{intent_uid}/payment-auth', 'StripeController@paymentAuth');

    // Stripe Subscription (Category B, intent-based)
    Route::get('/cashier/stripe-subscription/checkout/{intent_uid}', 'StripeSubscriptionController@checkout');
    Route::post('/cashier/stripe-subscription/pay/{intent_uid}', 'StripeSubscriptionController@pay')
        ->middleware('throttle:10,1');
});

// Webhooks (no CSRF, no throttle — Stripe sends bursts)
Route::group(['namespace' => 'App\Cashier\Controllers'], function () {
    Route::post('/cashier/webhooks/stripe-subscription', 'RemoteSubscriptionWebhookController@stripeSubscription');
});
