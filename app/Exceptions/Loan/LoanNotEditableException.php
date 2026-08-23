<?php

namespace App\Exceptions\Loan;

use Exception;

class LoanNotEditableException extends Exception
{
    public function __construct(string $message = 'This loan can no longer be modified.')
    {
        parent::__construct($message);
    }
}
