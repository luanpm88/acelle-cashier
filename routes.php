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

Route::group(['middleware' => ['web','not_installed'], 'namespace' => 'App\Cashier\Controllers'], function () {
    // direct
    Route::get('/cashier/offline/checkout/{invoice_uid}', 'OfflineController@checkout');
    Route::post('/cashier/offline/{invoice_uid}//{payment_gateway_id}/claim', 'OfflineController@claim');

    // Stripe
    Route::match(['get', 'post'], '/cashier/stripe/{invoice_uid}/{payment_gateway_id}/payment-auth', 'StripeController@paymentAuth');
    Route::match(['get', 'post'], '/cashier/stripe/checkout/{invoice_uid}/{payment_gateway_id}', 'StripeController@checkout');

    // Braintree
    Route::match(['get', 'post'], '/cashier/braintree/checkout/{invoice_uid}/{payment_gateway_id}', 'BraintreeController@checkout');

    // Paystack
    Route::match(['get', 'post'], '/cashier/paystack/checkout/{invoice_uid}/{payment_gateway_id}', 'PaystackController@checkout');
    Route::post('/cashier/paystack/{invoice_uid}/charge', 'PaystackController@charge');

    // Paypal
    Route::match(['get', 'post'], '/cashier/paypal/checkout/{invoice_uid}/{payment_gateway_id}', 'PaypalController@checkout');

    // Razorpay
    Route::match(['get', 'post'], '/cashier/razorpay/checkout/{invoice_uid}/{payment_gateway_id}', 'RazorpayController@checkout');
});