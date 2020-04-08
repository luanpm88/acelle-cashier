<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the controller to call when that URI is requested.
|
*/

Route::group(['middleware' => ['web'], 'namespace' => 'Acelle\Cashier\Controllers'], function() {
    // PayPal Subscription
    Route::get('/cashier/paypal-subscription/{subscription_id}/transaction-pending', 'PaypalSubscriptionController@transactionPending');
    Route::get('/cashier/paypal-subscription/{subscription_id}/change-plan/pending', 'PaypalSubscriptionController@ChangePlanpending');
    Route::get('/cashier/paypal-subscription/payment-redirect', 'PaypalSubscriptionController@paymentRedirect');
    Route::post('/cashier/paypal-subscription/{subscription_id}/cancel-now', 'PaypalSubscriptionController@cancelNow');
    Route::match(['get', 'post'], '/cashier/paypal-subscription/{subscription_id}/change-plan', 'PaypalSubscriptionController@changePlan');
    Route::match(['get', 'post'], '/cashier/paypal-subscription/{subscription_id}/renew', 'PaypalSubscriptionController@renew');
    Route::get('/cashier/paypal-subscription/{subscription_id}/pending', 'PaypalSubscriptionController@pending');
    Route::post('/cashier/paypal-subscription/{subscription_id}/checkout', 'PaypalSubscriptionController@checkout');
    Route::get('/cashier/paypal-subscription/{subscription_id}/checkout', 'PaypalSubscriptionController@checkout');

    // PayPal
    Route::get('/cashier/paypal/payment-redirect', 'PaypalController@paymentRedirect');
    Route::post('/cashier/paypal/{subscription_id}/cancel-now', 'PaypalController@cancelNow');
    Route::match(['get', 'post'], '/cashier/paypal/{subscription_id}/change-plan', 'PaypalController@changePlan');
    Route::match(['get', 'post'], '/cashier/paypal/{subscription_id}/renew', 'PaypalController@renew');
    Route::post('/cashier/paypal/{subscription_id}/checkout', 'PaypalController@checkout');
    Route::get('/cashier/paypal/{subscription_id}/checkout', 'PaypalController@checkout');

    // Braintree
    Route::get('/cashier/braintree/payment-redirect', 'BraintreeController@paymentRedirect');
    Route::match(['get', 'post'], '/cashier/braintree/{subscription_id}/fix-payment', 'BraintreeController@fixPayment');
    Route::get('/cashier/braintree/{subscription_id}/renew/pending', 'BraintreeController@renewPending');
    Route::match(['get', 'post'], '/cashier/braintree/{subscription_id}/renew', 'BraintreeController@renew');
    Route::get('/cashier/braintree/{subscription_id}/change-plan-pending', 'BraintreeController@changePlanPending');
    Route::match(['get', 'post'], '/cashier/braintree/{subscription_id}/change-plan', 'BraintreeController@changePlan');
    Route::post('/cashier/braintree/{subscription_id}/cancel-now', 'BraintreeController@cancelNow');
    Route::get('/cashier/braintree/{subscription_id}/pending', 'BraintreeController@pending');
    Route::match(['get', 'post'], '/cashier/braintree/{subscription_id}/charge', 'BraintreeController@charge');
    Route::post('/cashier/braintree/{subscription_id}/update-card', 'BraintreeController@updateCard');
    Route::get('/cashier/braintree/{subscription_id}/checkout', 'BraintreeController@checkout');
    
    // Coinpayments
    Route::get('/cashier/coinpayments/{subscription_id}/transaction-pending', 'CoinpaymentsController@transactionPending');
    Route::post('/cashier/coinpayments/{subscription_id}/cancel-now', 'CoinpaymentsController@cancelNow');
    Route::match(['get', 'post'], '/cashier/coinpayments/{subscription_id}/change-plan', 'CoinpaymentsController@changePlan');
    Route::match(['get', 'post'], '/cashier/coinpayments/{subscription_id}/renew', 'CoinpaymentsController@renew');
    Route::get('/cashier/coinpayments/{subscription_id}/pending', 'CoinpaymentsController@pending');
    Route::match(['get', 'post'], '/cashier/coinpayments/{subscription_id}/charge', 'CoinpaymentsController@charge');
    Route::get('/cashier/coinpayments/{subscription_id}/checkout', 'CoinpaymentsController@checkout');
    
    // Direct
    Route::post('/cashier/direct/{subscription_id}/cancel-now', 'DirectController@cancelNow');
    Route::match(['get', 'post'], '/cashier/direct/{subscription_id}/change-plan', 'DirectController@changePlan');
    Route::match(['get', 'post'], '/cashier/direct/{subscription_id}/renew', 'DirectController@renew');
    Route::post('/cashier/direct/{subscription_id}/pending-unclaim', 'DirectController@pendingUnclaim');
    Route::post('/cashier/direct/{subscription_id}/pending-claim', 'DirectController@pendingClaim');
    Route::get('/cashier/direct/{subscription_id}/pending', 'DirectController@pending');
    Route::post('/cashier/direct/{subscription_id}/unclaim', 'DirectController@unclaim');
    Route::post('/cashier/direct/{subscription_id}/claim', 'DirectController@claim');
    Route::get('/cashier/direct/{subscription_id}/checkout', 'DirectController@checkout');
    
    // Stripe
    Route::get('/cashier/stripe/payment-redirect', 'StripeController@paymentRedirect');
    Route::match(['get', 'post'], '/cashier/stripe/{subscription_id}/fix-payment', 'StripeController@fixPayment');
    Route::post('/cashier/stripe/{subscription_id}/cancel-now', 'StripeController@cancelNow');
    Route::get('/cashier/stripe/{subscription_id}/change-plan-pending', 'StripeController@changePlanPending');
    Route::match(['get', 'post'], '/cashier/stripe/{subscription_id}/change-plan', 'StripeController@changePlan');
    Route::match(['get', 'post'], '/cashier/stripe/{subscription_id}/charge', 'StripeController@charge');
    Route::post('/cashier/stripe/{subscription_id}/update-card', 'StripeController@updateCard');
    Route::get('/cashier/stripe/{subscription_id}/checkout', 'StripeController@checkout');
    
    // Razorpay
    Route::match(['get', 'post'], '/cashier/razorpay/{subscription_id}/change-plan', 'RazorpayController@changePlan');
    Route::match(['get', 'post'], '/cashier/razorpay/{subscription_id}/renew', 'RazorpayController@renew');
    Route::match(['get', 'post'], '/cashier/razorpay/{subscription_id}/charge', 'RazorpayController@charge');
    Route::post('/cashier/razorpay/{subscription_id}/update-card', 'RazorpayController@updateCard');
    Route::match(['get', 'post'], '/cashier/razorpay/{subscription_id}/checkout', 'RazorpayController@checkout');
});