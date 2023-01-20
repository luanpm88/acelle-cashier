<?php

namespace Acelle\Cashier\Library;

use Exception;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Acelle\Library\Facades\Billing;

class AutoBillingData
{
    protected $gateway;
    protected $data;

    public function __construct(PaymentGatewayInterface $gateway, $data = [])
    {
        $this->gateway = $gateway;
        $this->data = $data;
    }

    public function toJson()
    {
        return json_encode([
            'type' => $this->gateway->getType(),
            'data' => $this->data,
        ]);
    }

    public function getGateway()
    {
        return $this->gateway;
    }

    public function getData()
    {
        return $this->data['data'];
    }

    public static function fromJson($json)
    {
        $data = json_decode($json, true);

        if (!isset($data['type'])) {
            throw new Exception('Missing type from auto billing data json');
        }

        // service is not registered -> no valid AutoBillingData -> return null
        if (!Billing::isGatewayRegistered($data['type'])) {
            return null;
        }

        $gateway = Billing::getGateway($data['type']);

        unset($data['type']);

        return new self($gateway, $data);
    }
}
