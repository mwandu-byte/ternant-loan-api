<?php

namespace App\Exceptions\Repayment;

use Exception;

class LoanNotEligibleForRepaymentScheduleException extends Exception
{
    public function __construct(string $message = 'Only active loans are eligible for repayment schedule generation.')
    {
        parent::__construct($message);
    }
}
