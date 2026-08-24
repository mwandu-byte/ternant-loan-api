<?php

namespace App\Exceptions\Receipt;

use Exception;

class DuplicateReceiptReferenceException extends Exception
{
    public function __construct(string $message = 'A receipt already exists with this reference number.')
    {
        parent::__construct($message);
    }
}
