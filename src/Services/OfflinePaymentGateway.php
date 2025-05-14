<?php

namespace Acelle\Cashier\Services;

use Acelle\Library\Contracts\PaymentGatewayInterface;
use Acelle\Library\TransactionResult;
use Acelle\Model\Transaction;
use Acelle\Model\PaymentMethod;

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

    public function getCheckoutUrl($invoice, $paymentGatewayId) : string
    {
        return action("\Acelle\Cashier\Controllers\OfflineController@checkout", [
            'invoice_uid' => $invoice->uid,
            'payment_gateway_id' => $paymentGatewayId,
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

    public function autoCharge($invoice, PaymentMethod $paymentMethod)
    {
        throw new \Exception('Offline payment gateway does not support auto charge!');
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

    // get method title
    public function getMethodTitle($billingData)
    {
        return trans('cashier::messages.offline');
    }

    // get method info
    public function getMethodInfo($billingData)
    {
        return trans('cashier::messages.offline.description');
    }
}
