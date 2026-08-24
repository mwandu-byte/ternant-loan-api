<?php

namespace App\Exceptions\User;

use Exception;

class UserHasRelatedRecordsException extends Exception
{
    public function __construct(string $message = 'This user cannot be deleted because related financial records exist.')
    {
        parent::__construct($message);
    }
}
