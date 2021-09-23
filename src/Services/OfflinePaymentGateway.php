<?php

namespace Acelle\Cashier\Services;

use Stripe\Card as StripeCard;
use Stripe\Token as StripeToken;
use Stripe\Customer as StripeCustomer;
use Stripe\Subscription as StripeSubscription;
use Acelle\Cashier\Cashier;
use Acelle\Library\Contracts\PaymentGatewayInterface;
use Carbon\Carbon;
use Acelle\Model\Invoice;
use Acelle\Library\TransactionVerificationResult;
use Acelle\Model\Transaction;

class OfflinePaymentGateway implements PaymentGatewayInterface
{
    protected $paymentInstruction;
    protected $active = false;

    public function __construct($paymentInstruction)
    {
        $this->paymentInstruction = $paymentInstruction;

        $this->validate();
    }

    public function getName() : string
    {
        return 'Offline';
    }

    public function getType() : string
    {
        return 'offline';
    }

    public function getDescription() : string
    {
        return 'Receive payments outside of the application';
    }

    public function validate()
    {
        if (!$this->getPaymentInstruction()) {
            $this->active = false;
        } else {
            $this->active = true;
        }
    }

    public function isActive() : bool
    {
        return $this->active;
    }

    public function getSettingsUrl() : string
    {
        return action("\Acelle\Cashier\Controllers\OfflineController@settings");
    }

    public function getCheckoutUrl($invoice) : string
    {
        return action("\Acelle\Cashier\Controllers\OfflineController@checkout", [
            'invoice_uid' => $invoice->uid,
        ]);
    }

    public function supportsAutoBilling() : bool
    {
        return false;
    }

    public function verify(Transaction $transaction) : TransactionVerificationResult
    {
        return new TransactionVerificationResult(TransactionVerificationResult::RESULT_VERIFICATION_NOT_NEEDED);
    }

    public function allowManualReviewingOfTransaction() : bool
    {
        return true;
    }

    public function autoCharge($invoice)
    {
        throw new \Exception('Offline payment gateway does not support auto charge!');
    }

    public function getAutoBillingDataUpdateUrl($returnUrl='/') : string
    {
        throw new \Exception('
            Offline payment gateway does not support auto charge.
            Therefor method getAutoBillingDataUpdateUrl is not supported.
            Something wrong in your design flow!
            Check if a gateway supports auto billing by calling $gateway->supportsAutoBilling().
        ');
    }

    /**
     * Get payment guiline message.
     *
     * @return Boolean
     */
    public function getPaymentInstruction()
    {
        if ($this->paymentInstruction) {
            return $this->paymentInstruction;
        } else {
            return trans('cashier::messages.offline.payment_instruction.default');
        }
    }
    
    public function getMinimumChargeAmount($currency)
    {
        return 0;
    }
}
