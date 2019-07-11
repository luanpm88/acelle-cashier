<?php

namespace Acelle\Cashier\Interfaces;

interface BillableUserInterface
{
    public function getBillableId();
    public function getBillableEmail();
}