<?php

namespace App\Exceptions\Auth;

use Exception;

class InvalidResetTokenException extends Exception
{
    public function __construct(string $message = 'The reset code is invalid or has expired.')
    {
        parent::__construct($message);
    }
}
