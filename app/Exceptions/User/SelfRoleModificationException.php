<?php

namespace App\Exceptions\User;

use Exception;

class SelfRoleModificationException extends Exception
{
    public function __construct(string $message = 'You cannot modify your own role assignments.')
    {
        parent::__construct($message);
    }
}
