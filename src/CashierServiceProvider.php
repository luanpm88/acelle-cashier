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
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'cashier');

        $this->publishes([
            __DIR__.'/../resources/views' => $this->app->basePath('resources/views/vendor/cashier'),
        ]);
        
        // lang
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'cashier');
        
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
