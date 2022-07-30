<?php

namespace Acelle\Cashier;

use Illuminate\Support\ServiceProvider;
use Acelle\Library\Facades\Billing;
use Acelle\Cashier\Services\StripePaymentGateway;
use Acelle\Cashier\Services\OfflinePaymentGateway;
use Acelle\Cashier\Services\BraintreePaymentGateway;
use Acelle\Cashier\Services\CoinpaymentsPaymentGateway;
use Acelle\Cashier\Services\PaystackPaymentGateway;
use Acelle\Cashier\Services\PaypalPaymentGateway;
use Acelle\Cashier\Services\RazorpayPaymentGateway;
use Acelle\Model\Setting;
use Acelle\Library\Facades\Hook;

class CashierServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        // Only bootstraping the services if the application is already initialized
        if (!isInitiated()) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cashier');

        // lang
        $this->loadTranslationsFrom(storage_path('app/cashier/lang'), 'cashier');

        // routes
        $this->loadRoutesFrom(__DIR__.'/../routes.php');

        // view
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cashier');

        // assets
        $this->publishes([
            __DIR__.'/../assets' => public_path('vendor/acelle-cashier'),
        ], 'public');

        // register gateways
        $paymentInstruction = Setting::get('cashier.offline.payment_instruction');
        $offline = new OfflinePaymentGateway($paymentInstruction);
        Billing::register($offline);

        $publishableKey = Setting::get('cashier.stripe.publishable_key');
        $secretKey = Setting::get('cashier.stripe.secret_key');
        $stripe = new StripePaymentGateway($publishableKey, $secretKey);
        Billing::register($stripe);

        $environment = Setting::get('cashier.braintree.environment');
        $merchantId = Setting::get('cashier.braintree.merchant_id');
        $publicKey = Setting::get('cashier.braintree.public_key');
        $privateKey = Setting::get('cashier.braintree.private_key');
        $braintree = new BraintreePaymentGateway($environment, $merchantId, $publicKey, $privateKey);
        Billing::register($braintree);

        $merchantId = Setting::get('cashier.coinpayments.merchant_id');
        $publicKey = Setting::get('cashier.coinpayments.public_key');
        $privateKey = Setting::get('cashier.coinpayments.private_key');
        $ipnSecret = Setting::get('cashier.coinpayments.ipn_secret');
        $receiveCurrency = Setting::get('cashier.coinpayments.receive_currency');
        $coinpayments = new CoinpaymentsPaymentGateway($merchantId, $publicKey, $privateKey, $ipnSecret, $receiveCurrency);
        Billing::register($coinpayments);

        $publicKey = Setting::get('cashier.paystack.public_key');
        $secretKey = Setting::get('cashier.paystack.secret_key');
        $paystack = new PaystackPaymentGateway($publicKey, $secretKey);
        Billing::register($paystack);

        $environment = Setting::get('cashier.paypal.environment');
        $clientId = Setting::get('cashier.paypal.client_id');
        $secret = Setting::get('cashier.paypal.secret');
        $paypal = new PaypalPaymentGateway($environment, $clientId, $secret);
        Billing::register($paypal);

        $keyId = Setting::get('cashier.razorpay.key_id');
        $keySecret = Setting::get('cashier.razorpay.key_secret');
        $razorpay = new RazorpayPaymentGateway($keyId, $keySecret);
        Billing::register($razorpay);

        Hook::register('add_translation_file', function() {
            return [
                "id" => 'cashier_message',
                "plugin_name" => "Acelle/Cashier",
                "file_title" => "Cashier: messages",
                "translation_folder" => storage_path('app/cashier/lang'),
                "file_name" => "messages.php",
                "master_translation_file" => realpath(__DIR__.'/../resources/lang/en/messages.php'),
            ];
        });
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
    }
}
