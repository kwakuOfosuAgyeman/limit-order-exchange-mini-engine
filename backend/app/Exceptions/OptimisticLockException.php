<?php

namespace App\Exceptions;

use Exception;

class OptimisticLockException extends Exception
{
    public function __construct(string $message = 'Record was modified by another process. Please retry.')
    {
        parent::__construct($message);
    }
}
