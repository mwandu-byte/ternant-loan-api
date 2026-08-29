<?php

namespace App\Exceptions\Loan;

use Exception;

class NoApplicableInterestRuleException extends Exception
{
    public function __construct(string $message = 'No active interest rate rule covers this loan amount. An administrator must configure one before this loan can be created.')
    {
        parent::__construct($message);
    }
}
