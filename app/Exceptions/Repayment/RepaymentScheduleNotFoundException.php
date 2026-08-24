<?php

namespace App\Exceptions\Repayment;

use Exception;

class RepaymentScheduleNotFoundException extends Exception
{
    public function __construct(string $message = 'Repayment schedule not found.')
    {
        parent::__construct($message);
    }
}
