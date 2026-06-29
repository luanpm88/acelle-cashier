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

    // Stripe always uses the hosted Checkout page (no on-site form). Only the off-session-3DS
    // re-auth landing remains — it re-pays via a fresh hosted Checkout Session.
    Route::get('/cashier/stripe/{intent_uid}/payment-auth', 'StripeController@paymentAuth');
});

// Webhooks (no CSRF, no throttle — Stripe sends bursts). ONE Stripe endpoint for the
// merged gateway (subscription lifecycle + hosted-checkout completion events).
Route::group(['namespace' => 'App\Cashier\Controllers'], function () {
    Route::post('/cashier/webhooks/stripe', 'RemoteSubscriptionWebhookController@handle');
});
