<?php

namespace Acelle\Cashier;

use Illuminate\Support\ServiceProvider;

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
