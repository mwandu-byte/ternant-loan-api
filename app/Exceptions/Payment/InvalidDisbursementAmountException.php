<?php

namespace App\Exceptions\Payment;

use Exception;

class InvalidDisbursementAmountException extends Exception
{
    public function __construct(string $message = "Disbursement amount must equal the loan's principal amount.")
    {
        parent::__construct($message);
    }
}
