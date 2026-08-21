<?php

namespace App\Exceptions\Auth;

use Exception;

class InvalidRefreshTokenException extends Exception
{
    public function __construct(string $message = 'Invalid or expired refresh token.')
    {
        parent::__construct($message);
    }
}
