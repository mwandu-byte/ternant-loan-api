<?php

namespace App\Exceptions\Payment;

use Exception;

class LoanNotEligibleForDisbursementException extends Exception
{
    public function __construct(string $message = 'Only active loans are eligible for disbursement.')
    {
        parent::__construct($message);
    }
}
