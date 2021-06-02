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

Route::group(['middleware' => ['web'], 'namespace' => 'Acelle\Cashier\Controllers'], function () {
    // PayPal
    Route::match(['get', 'post'], '/cashier/paypal/{invoice_uid}/checkout', 'PaypalController@checkout');
    Route::match(['get', 'post'], '/cashier/paypal/connect', 'PaypalController@connect');

    // Braintree
    Route::match(['get', 'post'], '/cashier/braintree/{invoice_uid}/checkout', 'BraintreeController@checkout');
    Route::match(['get', 'post'], '/cashier/braintree/connect', 'BraintreeController@connect');
    
    // Coinpayments
    Route::match(['get', 'post'], '/cashier/coinpayments/{invoice_uid}/checkout', 'CoinpaymentsController@checkout');
    Route::match(['get', 'post'], '/cashier/coinpayments/connect', 'CoinpaymentsController@connect');
    
    // Direct
    Route::post('/cashier/direct/{invoice_uid}/claim', 'DirectController@claim');
    Route::get('/cashier/direct/{invoice_uid}/checkout', 'DirectController@checkout');
    Route::get('/cashier/direct/connect', 'DirectController@connect');
    
    // Stripe
    Route::match(['get', 'post'], '/cashier/stripe/{invoice_uid}/checkout', 'StripeController@checkout');
    Route::match(['get', 'post'], '/cashier/stripe/connect', 'StripeController@connect');
    
    // Razorpay
    Route::match(['get', 'post'], '/cashier/razorpay/{invoice_uid}/checkout', 'RazorpayController@checkout');
    Route::match(['get', 'post'], '/cashier/razorpay/connect', 'RazorpayController@connect');

    // Paystack
    Route::post('/cashier/paystack/{invoice_uid}/charge', 'PaystackController@charge');
    Route::match(['get', 'post'], '/cashier/paystack/{invoice_uid}/checkout', 'PaystackController@checkout');
    Route::match(['get', 'post'], '/cashier/paystack/connect', 'PaystackController@connect');
});
