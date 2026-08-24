<?php

namespace App\Exceptions\Payment;

use Exception;

class PaymentNotFoundException extends Exception
{
    public function __construct(string $message = 'Payment not found.')
    {
        parent::__construct($message);
    }
}
