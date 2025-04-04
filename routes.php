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

Route::group(['middleware' => ['web','not_installed'], 'namespace' => 'Acelle\Cashier\Controllers'], function () {
    // direct
    Route::get('/cashier/offline/checkout/{invoice_uid}', 'OfflineController@checkout');
    Route::post('/cashier/offline/{invoice_uid}/claim', 'OfflineController@claim');

    // Stripe
    Route::match(['get', 'post'], '/cashier/stripe/{invoice_uid}/payment-auth', 'StripeController@paymentAuth');
    Route::match(['get', 'post'], '/cashier/stripe/checkout/{invoice_uid}', 'StripeController@checkout');
    Route::match(['get', 'post'], '/cashier/stripe/auto-billing-update', 'StripeController@autoBillingDataUpdate');

    // Braintree
    Route::match(['get', 'post'], '/cashier/braintree/checkout/{invoice_uid}', 'BraintreeController@checkout');
    Route::match(['get', 'post'], '/cashier/braintree/auto-billing-update', 'BraintreeController@autoBillingDataUpdate');

    // coinpayments
    Route::match(['get', 'post'], '/cashier/coinpayments/{invoice_uid}', 'CoinpaymentsController@checkout');

    // Paystack
    Route::match(['get', 'post'], '/cashier/paystack/checkout/{invoice_uid}', 'PaystackController@checkout');
    Route::match(['get', 'post'], '/cashier/paystack/auto-billing-update', 'PaystackController@autoBillingDataUpdate');
    Route::post('/cashier/paystack/{invoice_uid}/charge', 'PaystackController@charge');

    // Paypal
    Route::match(['get', 'post'], '/cashier/paypal/checkout/{invoice_uid}', 'PaypalController@checkout');

    // Razorpay
    Route::match(['get', 'post'], '/cashier/razorpay/checkout/{invoice_uid}', 'RazorpayController@checkout');
});

Route::group(['middleware' => ['web', 'not_installed', 'auth', 'backend'], 'namespace' => 'Acelle\Cashier\Controllers'], function () {
    // direct
    Route::match(['get', 'post'], '/cashier/offline/settings', 'OfflineController@settings');

    // Stripe
    Route::match(['get', 'post'], '/cashier/stripe/settings', 'StripeController@settings');

    // Braintree
    Route::match(['get', 'post'], '/cashier/braintree/settings', 'BraintreeController@settings');

    // coinpayments
    Route::match(['get', 'post'], '/cashier/coinpayments/settings', 'CoinpaymentsController@settings');

    // Paystack
    Route::match(['get', 'post'], '/cashier/paystack/settings', 'PaystackController@settings');

    // Paypal
    Route::match(['get', 'post'], '/cashier/paypal/settings', 'PaypalController@settings');

    // Razorpay
    Route::match(['get', 'post'], '/cashier/razorpay/settings', 'RazorpayController@settings');
});