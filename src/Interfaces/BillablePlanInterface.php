<?php

namespace Acelle\Cashier\Interfaces;

interface BillablePlanInterface
{
    public function getBillableId();
    
    public function getBillableName();
    public function getBillableInterval();
    public function getBillableIntervalCount();
    public function getBillableCurrency();
    public function getBillableAmount();
    public function getBillableFormattedPrice();
}