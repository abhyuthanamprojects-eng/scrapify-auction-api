<?php

namespace App\Services\Verification;

use App\Exceptions\VerificationProviderException;

class PANVerificationService implements VerificationProviderInterface
{
    public function isEnabled(): bool
    {
        return app(VerificationProviderResolver::class)->for('PAN')->isEnabled();
    }

    /**
     * Validate Indian PAN format (5 letters + 4 digits + 1 letter).
     */
    public function verify(array $payload): array
    {
        $pan = strtoupper(trim($payload['pan_number'] ?? ''));

        // Standard Indian PAN Regex: 5 letters + 4 digits + 1 letter
        $panPattern = '/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/';

        if (empty($pan)) {
            return [
                'status' => 'not_checked',
                'message' => 'No PAN number provided.',
                'data' => null,
            ];
        }

        if (!preg_match($panPattern, $pan)) {
            return [
                'status' => 'invalid',
                'message' => 'Invalid PAN format. Expected 10-character alphanumeric PAN (e.g. ABCDE1234F).',
                'data' => null,
            ];
        }

        $provider = app(VerificationProviderResolver::class)->for('PAN');
        if (! $provider->isEnabled() || ! $provider->isConfigured('PAN')) {
            return ['status' => 'pending', 'message' => 'KYC provider is not configured.', 'data' => ['provider' => strtoupper($provider->key()), 'code' => 'PROVIDER_NOT_CONFIGURED']];
        }

        try {
            $result = $provider->verifyPan($pan, $payload['name'] ?? null, $payload['date_of_birth'] ?? null);
            return ['status' => $result->isVerified() ? 'valid' : 'invalid', 'message' => $result->isVerified() ? 'PAN verified by the active provider.' : 'PAN verification failed.', 'data' => $result->toArray()];
        } catch (VerificationProviderException $exception) {
            return ['status' => 'pending', 'message' => 'KYC provider is temporarily unavailable.', 'data' => ['provider' => strtoupper($provider->key()), 'code' => $exception->errorCode]];
        }
    }
}
