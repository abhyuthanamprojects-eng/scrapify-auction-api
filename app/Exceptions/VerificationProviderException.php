<?php

namespace App\Exceptions;

use RuntimeException;

final class VerificationProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 503,
    ) {
        parent::__construct($message);
    }
}
