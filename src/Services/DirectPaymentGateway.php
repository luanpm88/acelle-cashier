<?php

namespace Acelle\Cashier\Services;

use Stripe\Card as StripeCard;
use Stripe\Token as StripeToken;
use Stripe\Customer as StripeCustomer;
use Stripe\Subscription as StripeSubscription;
use Acelle\Cashier\Cashier;
use Acelle\Cashier\Interfaces\PaymentGatewayInterface;
use Carbon\Carbon;

class DirectPaymentGateway implements PaymentGatewayInterface {
    const ERROR_PENDING_REJECTED = 'pending-rejected';

    public $payment_instruction;
    public $confirmation_message;

    public function __construct($payment_instruction, $confirmation_message)
    {
        $this->payment_instruction = $payment_instruction;
        $this->confirmation_message = $confirmation_message;

        \Carbon\Carbon::setToStringFormat('jS \o\f F');
    }

    /**
     * Get payment guiline message.
     *
     * @return Boolean
     */
    public function getPaymentInstruction()
    {
        if (config('cashier.gateways.direct.fields.payment_instruction')) {
            return config('cashier.gateways.direct.fields.payment_instruction');
        } else {
            return trans('cashier::messages.direct.payment_instruction.demo');
        }
            
    }

    /**
     * Service does not support auto recurring.
     *
     * @return boolean
     */
    public function supportsAutoBilling() {
        return false;
    }

    /**
     * Get checkout url.
     *
     * @return string
     */
    public function getCheckoutUrl($invoice, $returnUrl='/') {
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\DirectController@checkout", [
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
        return \Acelle\Cashier\Cashier::lr_action("\Acelle\Cashier\Controllers\DirectController@connect", [
            'return_url' => $returnUrl,
        ]);
    }
}