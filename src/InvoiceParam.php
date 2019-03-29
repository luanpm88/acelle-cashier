<?php

namespace Acelle\Cashier;

use Carbon\Carbon;

class InvoiceParam
{
    public $periodEndsAt;
    public $createdAt;
    public $amount;
    public $description;
    public $status;
    
    
    /**
     * Create a new Invoice param item instance.
     *
     * @param  Array  $options
     * @return void
     */
    public function __construct($options = [])
    {
        $has = get_object_vars($this);
        foreach ($has as $name => $oldValue) {
            $this->$name = isset($options[$name]) ? $options[$name] : NULL;
        }
    }
}