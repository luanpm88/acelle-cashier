<?php

namespace Acelle\Cashier\Services;

use Acelle\Cashier\Cashier;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Carbon\Carbon;
use Sample\PayPalClient;
use PayPalCheckoutSdk\Orders\OrdersGetRequest;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;

class PaypalPaymentGateway implements PaymentGatewayInterface
{
    public $client_id;
    public $secret;
    public $client;
    public $environment;
    
    public function __construct($environment, $client_id, $secret)
    {
        $this->environment = $environment;
        $this->client_id = $client_id;
        $this->secret = $secret;

        if ($this->environment == 'sandbox') {
            $this->client = new PayPalHttpClient(new SandboxEnvironment($this->client_id, $this->secret));        
        } else {
            $this->client = new PayPalHttpClient(new ProductionEnvironment($this->client_id, $this->secret));    
        }
    }

    /**
     * Check if service is valid.
     *
     * @return void
     */
    public function validate()
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
     * Check invoice for paying.
     *
     * @return void
    */
    public function charge($invoice, $options=[])
    {
        try {
            $this->doCharge($invoice, $options);

            // pay invoice 
            $invoice->approve();
        } catch (\Exception $e) {
            // pay failed
            $invoice->payFailed($e->getMessage());
        }
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
     * Check if support recurring.
     *
     * @param  string    $userId
     * @return Boolean
     */
    public function supportsAutoBilling()
    {
        return false;
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
        foreach($response->result->links as $link)
        {
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

    /**
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($invoice, $returnUrl='/') {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\PaypalController@checkout", [
            'invoice_uid' => $invoice->uid,
            'return_url' => $returnUrl,
        ]);
    }
    
    /**
     * Get connect url.
     *
     * @return string
     */
    public function getConnectUrl($returnUrl='/') {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\PaypalController@connect", [
            'return_url' => $returnUrl,
        ]);
    }
}