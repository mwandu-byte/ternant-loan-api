<?php

namespace App\Exceptions\User;

use Exception;

class SelfDeletionNotAllowedException extends Exception
{
    public function __construct(string $message = 'You cannot delete your own account.')
    {
        parent::__construct($message);
    }
}
