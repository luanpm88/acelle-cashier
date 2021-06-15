<?php

namespace Acelle\Cashier;

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
}
