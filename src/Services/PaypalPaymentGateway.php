<?php

namespace Acelle\Cashier\Services;

use Acelle\Library\Contracts\PaymentGatewayInterface;
use PayPalCheckoutSdk\Orders\OrdersGetRequest;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use Acelle\Model\Invoice;
use Acelle\Library\TransactionResult;
use Acelle\Model\Transaction;
use Acelle\Model\PaymentMethod;

class PaypalPaymentGateway implements PaymentGatewayInterface
{
    public $clientId;
    public $secret;
    public $client;
    public $environment;
    public $active=false;

    public const TYPE = 'paypal';

    public function __construct($environment, $clientId, $secret)
    {
        $this->environment = $environment;
        $this->clientId = $clientId;
        $this->secret = $secret;

        if ($this->environment == 'sandbox') {
            $this->client = new PayPalHttpClient(new SandboxEnvironment($this->clientId, $this->secret));
        } else {
            $this->client = new PayPalHttpClient(new ProductionEnvironment($this->clientId, $this->secret));
        }

        $this->validate();
    }

    public function validate()
    {
        if (!$this->environment || !$this->clientId || !$this->secret) {
            $this->active = false;
        } else {
            $this->active = true;
        }
        
    }

    public function isActive() : bool
    {
        return $this->active;
    }

    public function getCheckoutUrl($invoice, $paymentGatewayId) : string
    {
        return action("\Acelle\Cashier\Controllers\PaypalController@checkout", [
            'invoice_uid' => $invoice->uid,
            'payment_gateway_id' => $paymentGatewayId,
        ]);
    }

    public function autoCharge($invoice, PaymentMethod $paymentMethod)
    {
        throw new \Exception('Paypal payment gateway does not support auto charge!');
    }

    public function allowManualReviewingOfTransaction() : bool
    {
        return false;
    }

    public function supportsAutoBilling() : bool
    {
        return false;
    }

    public function verify(Transaction $transaction) : TransactionResult
    {
        throw new \Exception("Payment service {$this->getType()} should not have pending transaction to verify");
    }
    
    public function charge($invoice, $paymentGateway, $options=[])
    {
        // Payment method
        $paymentMethod = $invoice->customer->paymentMethods()->updateOrCreate(
            [
                'payment_gateway_id' => $paymentGateway->id,
            ],
            [
                'can_auto_charge' => false,
            ]
        );

        $invoice->checkout($paymentMethod, function($invoice) use ($options) {
            try {
                // charge invoice
                $this->doCharge($invoice, $options);

                return new TransactionResult(TransactionResult::RESULT_DONE);
            } catch (\Exception $e) {
                return new TransactionResult(TransactionResult::RESULT_FAILED, $e->getMessage());
            }
        });
    }

    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function test()
    {
        try {
            $response = $this->client->execute(new OrdersGetRequest('ssssss'));
        } catch (\Exception $e) {
            $result = json_decode($e->getMessage(), true);
            if (isset($result['error']) && $result['error'] == 'invalid_client') {
                throw new \Exception($e->getMessage());
            }
        }
        
        return true;
    }
    
    /**
     * Create a new subscriptionParam.
     *
     * @param  mixed              $token
     * @param  SubscriptionParam  $param
     * @return void
     */
    public function doCharge($invoice, $options=[])
    {
        // check order ID
        $this->checkOrderID($options['orderID']);
    }

    /**
     * Swap subscription plan.
     *
     * @param  Subscription  $subscription
     * @return date
     */
    public function checkOrderID($orderID)
    {
        // Check payment status
        $response = $this->client->execute(new OrdersGetRequest($orderID));

        /**
         *Enable the following line to print complete response as JSON.
        */
        //print json_encode($response->result);
        print "Status Code: {$response->statusCode}\n";
        print "Status: {$response->result->status}\n";
        print "Order ID: {$response->result->id}\n";
        print "Intent: {$response->result->intent}\n";
        print "Links:\n";
        foreach ($response->result->links as $link) {
            print "\t{$link->rel}: {$link->href}\tCall Type: {$link->method}\n";
        }
        // 4. Save the transaction in your database. Implement logic to save transaction to your database for future reference.
        print "Gross Amount: {$response->result->purchase_units[0]->amount->currency_code} {$response->result->purchase_units[0]->amount->value}\n";

        // To print the whole response body, uncomment the following line
        // echo json_encode($response->result, JSON_PRETTY_PRINT);

        // if failed
        if ($response->statusCode != 200 || $response->result->status != 'COMPLETED') {
            throw new \Exception('Something went wrong:' . json_encode($response->result));
        }
    }

    public function getMinimumChargeAmount($currency)
    {
        return 0;
    }

    // get method title
    public function getMethodTitle($billingData)
    {
        return trans('cashier::messages.paypal');
    }

    // get method info
    public function getMethodInfo($billingData)
    {
        return trans('cashier::messages.paypal.description');
    }
}
