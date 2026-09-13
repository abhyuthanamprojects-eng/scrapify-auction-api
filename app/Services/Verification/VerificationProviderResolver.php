<?php

namespace App\Services\Verification;

use App\Contracts\BusinessVerificationProviderInterface;
use App\Exceptions\VerificationProviderException;
use App\Services\GeneralSettings;

final class VerificationProviderResolver
{
    public const SANDBOX = 'sandbox';
    public const CASHFREE = 'cashfree';

    /** @var array<string, class-string<BusinessVerificationProviderInterface>> */
    private const PROVIDERS = [
        self::SANDBOX => SandboxVerificationProvider::class,
        self::CASHFREE => CashfreeSecureIdProvider::class,
    ];

    public function providerKey(string $verificationType): string
    {
        $type = strtoupper($verificationType);
        $setting = match ($type) {
            'GSTIN' => 'gst_verification_provider',
            'BANK' => 'bank_verification_provider',
            default => 'kyc_verification_provider',
        };

        $key = strtolower(GeneralSettings::string($setting, self::SANDBOX));
        if (! array_key_exists($key, self::PROVIDERS)) {
            throw new VerificationProviderException('PROVIDER_INVALID', 'The selected verification provider is invalid.', 422);
        }

        return $key;
    }

    public function for(string $verificationType): BusinessVerificationProviderInterface
    {
        $key = $this->providerKey($verificationType);
        return app(self::PROVIDERS[$key]);
    }

    public function configured(string $verificationType): bool
    {
        $provider = $this->for($verificationType);
        return $provider->isEnabled() && $provider->isConfigured($verificationType);
    }

    public function status(): array
    {
        $gstProvider = $this->for('GSTIN');
        $kycProvider = $this->for('KYC');
        $bankProvider = $this->for('BANK');

        return [
            'gst' => [
                'active_provider' => strtoupper($this->providerKey('GSTIN')),
                'providers' => $this->providerStatuses('GSTIN'),
            ],
            'kyc' => [
                'active_provider' => strtoupper($this->providerKey('KYC')),
                'providers' => $this->providerStatuses('KYC'),
            ],
            'bank' => [
                'active_provider' => strtoupper($this->providerKey('BANK')),
                'providers' => $this->providerStatuses('BANK'),
            ],
        ];
    }

    public function test(string $verificationType, array $payload): NormalizedVerificationResult
    {
        $type = strtoupper($verificationType);
        $provider = $this->for($type);
        if (! $provider->isEnabled()) {
            throw new VerificationProviderException('PROVIDER_DISABLED', $provider->label().' is disabled.', 422);
        }
        if (! $provider->isConfigured($type)) {
            throw new VerificationProviderException('PROVIDER_NOT_CONFIGURED', $provider->label().' is not configured.', 422);
        }

        return match ($type) {
            'GSTIN' => $provider->verifyGstin((string) ($payload['gstin'] ?? ''), $payload['business_name'] ?? null),
            'PAN' => $provider->verifyPan((string) ($payload['pan'] ?? ''), $payload['name'] ?? null, $payload['date_of_birth'] ?? null),
            'KYC' => $provider->verifyPan((string) ($payload['pan'] ?? ''), $payload['name'] ?? null, $payload['date_of_birth'] ?? null),
            'BANK' => $provider->verifyBankAccount((string) ($payload['bank_account'] ?? ''), (string) ($payload['ifsc'] ?? ''), $payload['name'] ?? null, $payload['phone'] ?? null),
            default => throw new VerificationProviderException('VERIFICATION_TYPE_INVALID', 'The verification type is invalid.', 422),
        };
    }

    /** @return array<string, array<string, mixed>> */
    private function providerStatuses(string $verificationType): array
    {
        $statuses = [];
        foreach (self::PROVIDERS as $key => $class) {
            $provider = app($class);
            $enabled = $provider->isEnabled();
            $configured = $enabled && $provider->isConfigured($verificationType);
            $statuses[$key] = [
                'key' => strtoupper($key),
                'label' => $provider->label(),
                'enabled' => $enabled,
                'configured' => $configured,
                'status' => ! $enabled ? 'DISABLED' : ($configured ? 'CONFIGURED' : 'NOT_CONFIGURED'),
                'last_successful_at' => GeneralSettings::string('verification_'.$key.'_last_successful_at', ''),
            ];
        }

        return $statuses;
    }
}
