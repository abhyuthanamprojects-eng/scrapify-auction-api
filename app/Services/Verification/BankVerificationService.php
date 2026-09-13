<?php

namespace App\Services\Verification;

use App\Exceptions\VerificationProviderException;

class BankVerificationService implements VerificationProviderInterface
{
    public function isEnabled(): bool
    {
        return app(VerificationProviderResolver::class)->for('BANK')->isEnabled();
    }

    /**
     * Validate Bank IFSC and Account details with Penny Drop provider support.
     */
    public function verify(array $payload): array
    {
        $accountNo = trim($payload['account_number'] ?? '');
        $ifsc = strtoupper(trim($payload['ifsc_code'] ?? ''));

        // Standard Indian IFSC Regex: 4 letters bank code + 0 + 6 alphanumeric branch code
        $ifscPattern = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

        if (empty($accountNo) || empty($ifsc)) {
            return [
                'status' => 'not_checked',
                'message' => 'Incomplete bank details provided.',
                'data' => null,
            ];
        }

        if (!preg_match($ifscPattern, $ifsc)) {
            return [
                'status' => 'invalid',
                'message' => 'Invalid IFSC code format (e.g. HDFC0001234, SBIN0000456).',
                'data' => null,
            ];
        }

        if (! preg_match('/^[A-Za-z0-9]{6,40}$/', $accountNo)) {
            return [
                'status' => 'invalid',
                'message' => 'Account number must be between 6 and 40 letters or digits.',
                'data' => null,
            ];
        }

        try {
            $provider = app(VerificationProviderResolver::class)->for('BANK');
            $result = $provider->verifyBankAccount(
                $accountNo,
                $ifsc,
                $payload['account_holder_name'] ?? null,
                $payload['phone'] ?? null,
            );

            return [
                'status' => $result->isVerified() ? 'valid' : 'invalid',
                'message' => $result->isVerified() ? 'Bank account verified.' : 'Bank account could not be verified.',
                'data' => $result->toArray() + ['account_number' => $accountNo, 'ifsc' => $ifsc],
            ];
        } catch (VerificationProviderException $exception) {
            return [
                'status' => 'pending',
                'message' => $exception->getMessage(),
                'data' => ['error_code' => $exception->errorCode],
            ];
        }
    }
}
