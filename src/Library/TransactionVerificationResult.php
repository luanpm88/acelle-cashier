<?php

namespace Acelle\Cashier\Library;

class TransactionVerificationResult
{
    public const RESULT_DONE = 'done';
    public const RESULT_FAILED = 'failed';
    public const RESULT_STILL_PENDING = 'still-pending';

    // For Stripe / PayPal / Braintree only when the service will start and finish the transaction immediately
    public const RESULT_VERIFICATION_NOT_NEEDED = 'not-needed';

    public $result;
    public $error;

    public function __construct($result, $error = null)
    {
        $this->result = $result;
        $this->error = $error;
    }

    public function isDone()
    {
        // normally run invoice.fulfill() after that
        return $this->result == self::RESULT_DONE;
    }

    public function isFailed()
    {
        // run invoice.payfailed() after that
        return $this->result == self::RESULT_FAILED;
    }

    public function isStillPending()
    {
        // Normally for services that immediately returns a result already
        //
        return $this->result == self::RESULT_STILL_PENDING;
    }

    public function isVerificationNotNeeded()
    {
        return $this->result = self::RESULT_VERIFICATION_NOT_NEEDED;
    }
}
