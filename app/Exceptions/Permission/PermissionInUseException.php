<?php

namespace App\Exceptions\Permission;

use Exception;

class PermissionInUseException extends Exception
{
    public function __construct(string $message = 'This permission cannot be deleted because it is currently in use.')
    {
        parent::__construct($message);
    }
}
