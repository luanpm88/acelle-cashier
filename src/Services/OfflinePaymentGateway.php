<?php

namespace Acelle\Cashier\Services;

use Acelle\Library\Contracts\PaymentGatewayInterface;
use Acelle\Library\TransactionResult;
use Acelle\Model\Transaction;

class OfflinePaymentGateway implements PaymentGatewayInterface
{
    protected $paymentInstruction;
    protected $active = false;

    public const TYPE = 'offline';

    public function __construct($paymentInstruction)
    {
        $this->paymentInstruction = $paymentInstruction;

        $this->validate();
    }

    public function getName() : string
    {
        return trans('cashier::messages.offline');
    }

    public function getType() : string
    {
        return self::TYPE;
    }

    public function getDescription() : string
    {
        return trans('cashier::messages.offline.description');
    }

    public function getShortDescription() : string
    {
        return trans('cashier::messages.offline.short_description');
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

    public function verify(Transaction $transaction) : TransactionResult
    {
        // do nothing because offline need admin to approve
        return new TransactionResult(TransactionResult::RESULT_PENDING);
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
