<?php

namespace App\Exceptions\Role;

use Exception;

class RoleAssignedToUsersException extends Exception
{
    public function __construct(string $message = 'This role cannot be deleted because it is assigned to one or more users.')
    {
        parent::__construct($message);
    }
}
