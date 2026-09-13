<?php

namespace App\Exceptions;

use RuntimeException;

final class PincodeProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 503,
        public readonly int $retryAfter = 30,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
