<?php

namespace App\Exceptions\Repayment;

use Exception;

class RepaymentExceedsOutstandingAmountException extends Exception
{
    public function __construct(string $message = 'Repayment amount exceeds the outstanding balance for this installment and any subsequent unpaid installments on this loan.')
    {
        parent::__construct($message);
    }
}
