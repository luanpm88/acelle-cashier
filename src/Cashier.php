<?php

namespace App\Cashier;

use Illuminate\Support\ServiceProvider;

class Cashier
{
    /**
     * Get payment gateway.
     *
     * @var array
     */
    public static function wp_action($name, $parameters = [], $absolute = true)
    {
        if (defined('WORDPRESS_MODE')) {
            return wp_action($name, $parameters, $absolute);
        } else {
            return action($name, $parameters, $absolute);
        }
    }
    public static function lr_action($name, $parameters = [], $absolute = true)
    {
        if (defined('WORDPRESS_MODE')) {
            return lr_action($name, $parameters, $absolute);
        } else {
            return action($name, $parameters, $absolute);
        }
    }
    public static function public_url($path)
    {
        if (defined('WORDPRESS_MODE')) {
            return public_url($path);
        } else {
            return url($path);
        }
    }

    /**
     * Checkout stylesheet, inlined into the page.
     *
     * It is NOT linked from public/vendor/acelle-cashier: that directory only
     * exists after `vendor:publish --tag=public`, is gitignored by the host app
     * (so no patch ever ships it) and no upgrade step re-publishes it. Installs
     * therefore served a 404 for the stylesheet and rendered the checkout raw —
     * the "giant padlock, one column" page customers reported. Reading it from
     * the package makes the checkout correct on every install with no publish
     * step. Missing file = broken package, so let it throw rather than serve an
     * unstyled page again.
     */
    public static function checkout_css()
    {
        $path = __DIR__.'/../assets/css/main.css';

        $css = file_get_contents($path);
        if ($css === false) {
            throw new \RuntimeException("Cashier checkout stylesheet is unreadable: {$path}");
        }

        return $css;
    }
}
