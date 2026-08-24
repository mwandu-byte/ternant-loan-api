<?php

namespace App\Exceptions\Payment;

use Exception;

class LoanAlreadyDisbursedException extends Exception
{
    public function __construct(string $message = 'This loan has already been disbursed.')
    {
        parent::__construct($message);
    }
}
