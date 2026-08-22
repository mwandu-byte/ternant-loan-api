<?php

namespace App\Exceptions\Customer;

use Exception;

class CustomerHasRelatedRecordsException extends Exception
{
    public function __construct(string $message = 'This customer cannot be deleted because related records exist.')
    {
        parent::__construct($message);
    }
}
