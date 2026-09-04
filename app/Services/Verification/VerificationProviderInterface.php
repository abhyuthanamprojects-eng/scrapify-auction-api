<?php

namespace App\Services\Verification;

interface VerificationProviderInterface
{
    /**
     * Determine whether automated external verification is enabled.
     */
    public function isEnabled(): bool;

    /**
     * Perform verification on given entity details.
     *
     * @param array<string, mixed> $payload
     * @return array{status: string, message: string, data: array<string, mixed>|null}
     */
    public function verify(array $payload): array;
}
