<?php

namespace App\Exceptions\Collateral;

use Exception;

class CollateralNotFoundException extends Exception
{
    public function __construct(string $message = 'Collateral not found.')
    {
        parent::__construct($message);
    }
}
