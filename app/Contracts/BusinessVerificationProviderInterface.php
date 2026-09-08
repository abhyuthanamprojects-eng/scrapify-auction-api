<?php

namespace App\Contracts;

interface BusinessVerificationProviderInterface
{
    public function verifyGstin(string $gstin, ?string $businessName = null): array;
    public function verifyBankAccount(string $account, string $ifsc, ?string $name = null, ?string $phone = null): array;
}
