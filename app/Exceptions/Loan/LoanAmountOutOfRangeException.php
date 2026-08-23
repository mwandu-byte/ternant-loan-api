<?php

namespace App\Exceptions\Loan;

use Exception;

class LoanAmountOutOfRangeException extends Exception
{
    public function __construct(string $message = 'Loan amount is outside the configured lending range.')
    {
        parent::__construct($message);
    }
}
