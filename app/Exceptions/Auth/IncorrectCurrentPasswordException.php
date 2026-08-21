<?php

namespace App\Exceptions\Auth;

use Exception;

class IncorrectCurrentPasswordException extends Exception
{
    public function __construct(string $message = 'The current password is incorrect.')
    {
        parent::__construct($message);
    }
}
