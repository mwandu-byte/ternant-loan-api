<?php

namespace App\Exceptions\Repayment;

use Exception;

class RepaymentNotFoundException extends Exception
{
    public function __construct(string $message = 'Repayment not found.')
    {
        parent::__construct($message);
    }
}
