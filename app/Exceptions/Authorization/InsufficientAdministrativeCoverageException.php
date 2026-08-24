<?php

namespace App\Exceptions\Authorization;

use Exception;

class InsufficientAdministrativeCoverageException extends Exception
{
    public function __construct(string $message = 'This action would leave no enabled user able to manage roles. Assign the roles.update permission to another user first.')
    {
        parent::__construct($message);
    }
}
