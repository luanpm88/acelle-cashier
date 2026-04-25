<?php

/*
|--------------------------------------------------------------------------
| Cashier routes
|--------------------------------------------------------------------------
|
| All gateways use the unified PaymentIntent flow ({intent_uid}).
|
| Security model: intent_uid is a capability token (UUIDv4 = 122 bits entropy).
| Pay endpoints are throttled to limit abuse if a uid leaks.
|
*/

Route::group(['middleware' => ['web', 'not_installed'], 'namespace' => 'App\Cashier\Controllers'], function () {
    // Offline (manual payment, admin approves later)
    Route::get('/cashier/offline/checkout/{intent_uid}', 'OfflineController@checkout');
    Route::post('/cashier/offline/claim/{intent_uid}', 'OfflineController@claim')
        ->middleware('throttle:10,1');

    // Stripe (one-off, intent-based)
    Route::get('/cashier/stripe/checkout/{intent_uid}', 'StripeController@checkout');
    Route::post('/cashier/stripe/pay/{intent_uid}', 'StripeController@pay')
        ->middleware('throttle:10,1');
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
