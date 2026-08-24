<?php

namespace App\Exceptions\Repayment;

use Exception;

class RepaymentScheduleDoesNotBelongToLoanException extends Exception
{
    public function __construct(string $message = 'The selected repayment schedule does not belong to the selected loan.')
    {
        parent::__construct($message);
    }
}
