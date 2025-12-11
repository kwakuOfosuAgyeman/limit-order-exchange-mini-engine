<?php

namespace App\Exceptions;

use Exception;

class InsufficientBalanceException extends Exception
{
    public function __construct(
        string $message = 'Insufficient balance',
        public readonly string $currency = 'USD',
        public readonly string $required = '0',
        public readonly string $available = '0'
    ) {
        parent::__construct($message);
    }
}
