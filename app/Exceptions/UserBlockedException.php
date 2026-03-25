<?php

namespace App\Exceptions;

use Exception;

class UserBlockedException extends Exception
{
    public function __construct(string $message = 'This action is blocked', int $code = 403)
    {
        parent::__construct($message, $code);
    }
}
