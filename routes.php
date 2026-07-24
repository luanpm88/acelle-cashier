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

    // Stripe has NO routes here: it always uses the hosted Checkout page (no on-site form),
    // entered exclusively via BillingManager::buildCheckoutUrl. The old off-session-3DS
    // re-auth landing (StripeController@paymentAuth) was REMOVED 2026-07-22: it was an
    // orphan (nothing ever generated/emailed its link) that charged OUTSIDE the sanctioned
    // lane — direct getCheckoutUrl, no PaymentIntentFactory re-check, CheckoutHandle
    // discarded (session never stamped → poller/return/webhook all blind → money could be
    // captured with no local settlement). If an email 3DS-re-auth feature is ever wanted,
    // build it THROUGH Billing::buildCheckoutUrl with auth + throttle.
});

// Webhooks (no CSRF, no throttle — Stripe sends bursts). ONE Stripe endpoint for the
// merged gateway (subscription lifecycle + hosted-checkout completion events).
Route::group(['namespace' => 'App\Cashier\Controllers'], function () {
    Route::post('/cashier/webhooks/stripe', 'RemoteSubscriptionWebhookController@handle');
});
