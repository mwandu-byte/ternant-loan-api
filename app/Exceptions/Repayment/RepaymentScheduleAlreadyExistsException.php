<?php

namespace App\Exceptions\Repayment;

use Exception;

class RepaymentScheduleAlreadyExistsException extends Exception
{
    public function __construct(string $message = 'A repayment schedule has already been generated for this loan.')
    {
        parent::__construct($message);
    }
}
