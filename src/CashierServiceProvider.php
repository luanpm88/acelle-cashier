<?php

namespace Acelle\Cashier;

use Illuminate\Support\ServiceProvider;
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
