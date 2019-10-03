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
    Route::match(['get', 'post'], '/cashier/coinpayments/{subscription_id}/change-plan', 'CoinpaymentsController@changePlan');
    Route::match(['get', 'post'], '/cashier/coinpayments/{subscription_id}/renew', 'CoinpaymentsController@renew');
    Route::get('/cashier/coinpayments/{subscription_id}/pending', 'CoinpaymentsController@pending');
    Route::match(['get', 'post'], '/cashier/coinpayments/{subscription_id}/charge', 'CoinpaymentsController@charge');
    Route::get('/cashier/coinpayments/{subscription_id}/checkout', 'CoinpaymentsController@checkout');
    
    Route::match(['get', 'post'], '/cashier/direct/{subscription_id}/change-plan', 'DirectController@changePlan');
    Route::match(['get', 'post'], '/cashier/direct/{subscription_id}/renew', 'DirectController@renew');
    Route::post('/cashier/direct/{subscription_id}/pending-unclaim', 'DirectController@pendingUnclaim');
    Route::post('/cashier/direct/{subscription_id}/pending-claim', 'DirectController@pendingClaim');
    Route::get('/cashier/direct/{subscription_id}/pending', 'DirectController@pending');
    Route::post('/cashier/direct/{subscription_id}/unclaim', 'DirectController@unclaim');
    Route::post('/cashier/direct/{subscription_id}/claim', 'DirectController@claim');
    Route::get('/cashier/direct/{subscription_id}/checkout', 'DirectController@checkout');
    
    Route::match(['get', 'post'], '/cashier/stripe/{subscription_id}/change-plan', 'StripeController@changePlan');
    Route::match(['get', 'post'], '/cashier/stripe/{subscription_id}/charge', 'StripeController@charge');
    Route::post('/cashier/stripe/{subscription_id}/update-card', 'StripeController@updateCard');
    Route::get('/cashier/stripe/{subscription_id}/checkout', 'StripeController@checkout');
});