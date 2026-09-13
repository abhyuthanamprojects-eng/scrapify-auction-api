<?php

namespace App\Services\Verification;

use App\Exceptions\VerificationProviderException;
use App\Services\GeneralSettings;

class GSTVerificationService implements VerificationProviderInterface
{
    public function isEnabled(): bool
    {
        return app(VerificationProviderResolver::class)->for('GSTIN')->isEnabled();
    }

    /**
     * Keep the legacy onboarding response shape while delegating the real
     * verification to the same resolver used by the KYB API.
     */
    public function verify(array $payload): array
    {
        $gstin = strtoupper(trim($payload['gst_number'] ?? ''));

        // Standard Indian GSTIN Regex: 2 digits state code + 10 char PAN + 1 entity + 1 Z + 1 checksum
        $gstPattern = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/';

        if (empty($gstin)) {
            return [
                'status' => 'not_checked',
                'message' => 'No GST number provided.',
                'data' => null,
            ];
        }

        if (!preg_match($gstPattern, $gstin)) {
            return [
                'status' => 'invalid',
                'message' => 'Invalid GSTIN format. Expected 15-character alphanumeric GSTIN.',
                'data' => null,
            ];
        }

        $provider = app(VerificationProviderResolver::class)->for('GSTIN');
        if (! $provider->isEnabled() || ! $provider->isConfigured('GSTIN')) {
            return ['status' => 'pending', 'message' => 'GST provider is not configured.', 'data' => ['provider' => strtoupper($provider->key()), 'code' => 'PROVIDER_NOT_CONFIGURED']];
        }

        try {
            $result = $provider->verifyGstin($gstin, $payload['company_name'] ?? null);
            return ['status' => $result->isVerified() ? 'valid' : 'invalid', 'message' => $result->isVerified() ? 'GSTIN verified by the active provider.' : 'GSTIN verification failed.', 'data' => $result->toArray()];
        } catch (VerificationProviderException $exception) {
            return ['status' => 'pending', 'message' => 'GST provider is temporarily unavailable.', 'data' => ['provider' => strtoupper($provider->key()), 'code' => $exception->errorCode]];
        }
    }
}
