<?php

namespace App\Exceptions\Penalty;

use Exception;

class PenaltyNotFoundException extends Exception
{
    public function __construct(string $message = 'Penalty not found.')
    {
        parent::__construct($message);
    }
}
