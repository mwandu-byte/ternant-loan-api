<?php

namespace App\Exceptions\Loan;

use Exception;

class LoanNotFoundException extends Exception
{
    public function __construct(string $message = 'Loan not found.')
    {
        parent::__construct($message);
    }
}
