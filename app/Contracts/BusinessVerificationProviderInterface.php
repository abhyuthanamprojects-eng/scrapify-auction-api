<?php

namespace App\Contracts;

use App\Services\Verification\NormalizedVerificationResult;

interface BusinessVerificationProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function isEnabled(): bool;

    public function isConfigured(string $verificationType): bool;

    public function verifyGstin(string $gstin, ?string $businessName = null): NormalizedVerificationResult;

    public function verifyPan(string $pan, ?string $name = null, ?string $dateOfBirth = null): NormalizedVerificationResult;

    public function verifyBankAccount(string $account, string $ifsc, ?string $name = null, ?string $phone = null): NormalizedVerificationResult;
}
