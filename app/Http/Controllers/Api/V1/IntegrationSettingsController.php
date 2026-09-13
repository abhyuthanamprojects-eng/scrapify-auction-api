<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GeneralSetting;
use App\Services\GeneralSettings;
use App\Services\Verification\VerificationProviderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class IntegrationSettingsController extends Controller
{
    private const SECRET_KEYS = [
        'cashfree_secure_id_client_id',
        'cashfree_secure_id_client_secret',
        'sandbox_verification_api_key',
        'sandbox_verification_api_secret',
        'mail_username',
        'mail_password',
        'pusher_app_key',
        'pusher_app_secret',
        'aws_access_key_id',
        'aws_secret_access_key',
    ];

    public function show(): JsonResponse
    {
        return response()->json([
            'firebase_api_key' => GeneralSettings::string('firebase_api_key', ''),
            'firebase_auth_domain' => GeneralSettings::string('firebase_auth_domain', ''),
            'firebase_project_id' => GeneralSettings::string('firebase_project_id', (string) config('services.google.firebase_project_id', '')),
            'firebase_storage_bucket' => GeneralSettings::string('firebase_storage_bucket', ''),
            'firebase_messaging_sender_id' => GeneralSettings::string('firebase_messaging_sender_id', ''),
            'firebase_app_id' => GeneralSettings::string('firebase_app_id', ''),
            'google_client_id' => GeneralSettings::string('google_client_id', (string) config('services.google.client_id', '')),
            'cashfree_secure_id_enabled' => GeneralSettings::bool('cashfree_secure_id_enabled', (bool) config('services.cashfree_secure_id.enabled', false)),
            'cashfree_secure_id_environment' => GeneralSettings::string('cashfree_secure_id_environment', (string) config('services.cashfree_secure_id.environment', 'sandbox')),
            'cashfree_secure_id_client_id' => $this->masked(GeneralSettings::secret('cashfree_secure_id_client_id', config('services.cashfree_secure_id.client_id'))),
            'cashfree_secure_id_client_secret' => $this->masked(GeneralSettings::secret('cashfree_secure_id_client_secret', config('services.cashfree_secure_id.client_secret'))),
            'cashfree_secure_id_base_url' => GeneralSettings::string('cashfree_secure_id_base_url', (string) config('services.cashfree_secure_id.base_url', '')),
            'cashfree_secure_id_timeout' => GeneralSettings::int('cashfree_secure_id_timeout', (int) config('services.cashfree_secure_id.timeout', 30)),
            'gst_verification_provider' => strtoupper(GeneralSettings::string('gst_verification_provider', VerificationProviderResolver::SANDBOX)),
            'kyc_verification_provider' => strtoupper(GeneralSettings::string('kyc_verification_provider', VerificationProviderResolver::SANDBOX)),
            'bank_verification_provider' => strtoupper(GeneralSettings::string('bank_verification_provider', VerificationProviderResolver::SANDBOX)),
            'sandbox_verification_enabled' => GeneralSettings::bool('sandbox_verification_enabled', (bool) config('services.sandbox_verification.enabled', true)),
            'sandbox_verification_environment' => GeneralSettings::string('sandbox_verification_environment', (string) config('services.sandbox_verification.environment', 'test')),
            'sandbox_verification_api_key' => $this->masked(GeneralSettings::secret('sandbox_verification_api_key', config('services.sandbox_verification.api_key'))),
            'sandbox_verification_api_secret' => $this->masked(GeneralSettings::secret('sandbox_verification_api_secret', config('services.sandbox_verification.api_secret'))),
            'sandbox_verification_base_url' => GeneralSettings::string('sandbox_verification_base_url', (string) config('services.sandbox_verification.base_url', '')),
            'sandbox_verification_api_version' => GeneralSettings::string('sandbox_verification_api_version', (string) config('services.sandbox_verification.api_version', '1.0.0')),
            'sandbox_verification_timeout' => GeneralSettings::int('sandbox_verification_timeout', (int) config('services.sandbox_verification.timeout', 30)),
            'verification_providers' => app(VerificationProviderResolver::class)->status(),
            'mail_mailer' => GeneralSettings::string('mail_mailer', (string) config('mail.default', 'smtp')),
            'mail_host' => GeneralSettings::string('mail_host', (string) config('mail.mailers.smtp.host', '')),
            'mail_port' => GeneralSettings::int('mail_port', (int) config('mail.mailers.smtp.port', 587)),
            'mail_username' => $this->masked(GeneralSettings::secret('mail_username', config('mail.mailers.smtp.username'))),
            'mail_password' => $this->masked(GeneralSettings::secret('mail_password', config('mail.mailers.smtp.password'))),
            'mail_encryption' => GeneralSettings::string('mail_encryption', (string) config('mail.mailers.smtp.scheme', 'tls')),
            'mail_from_address' => GeneralSettings::string('mail_from_address', (string) config('mail.from.address', '')),
            'mail_from_name' => GeneralSettings::string('mail_from_name', (string) config('mail.from.name', 'Scrapify Auctions')),
            'pusher_app_id' => GeneralSettings::string('pusher_app_id', (string) config('broadcasting.connections.pusher.app_id', '')),
            'pusher_app_key' => $this->masked(GeneralSettings::secret('pusher_app_key', config('broadcasting.connections.pusher.key'))),
            'pusher_app_secret' => $this->masked(GeneralSettings::secret('pusher_app_secret', config('broadcasting.connections.pusher.secret'))),
            'pusher_cluster' => GeneralSettings::string('pusher_cluster', (string) config('broadcasting.connections.pusher.options.cluster', 'mt1')),
            'pusher_host' => GeneralSettings::string('pusher_host', (string) config('broadcasting.connections.pusher.options.host', '')),
            'pusher_port' => GeneralSettings::int('pusher_port', (int) config('broadcasting.connections.pusher.options.port', 443)),
            'pusher_scheme' => GeneralSettings::string('pusher_scheme', (string) config('broadcasting.connections.pusher.options.scheme', 'https')),
            'filesystem_disk' => GeneralSettings::string('filesystem_disk', (string) config('filesystems.default', 'local')),
            'aws_access_key_id' => $this->masked(GeneralSettings::secret('aws_access_key_id', config('filesystems.disks.s3.key'))),
            'aws_secret_access_key' => $this->masked(GeneralSettings::secret('aws_secret_access_key', config('filesystems.disks.s3.secret'))),
            'aws_region' => GeneralSettings::string('aws_region', (string) config('filesystems.disks.s3.region', 'us-east-1')),
            'aws_bucket' => GeneralSettings::string('aws_bucket', (string) config('filesystems.disks.s3.bucket', '')),
            'aws_endpoint' => GeneralSettings::string('aws_endpoint', (string) config('filesystems.disks.s3.endpoint', '')),
            'aws_use_path_style_endpoints' => GeneralSettings::bool('aws_use_path_style_endpoints', (bool) config('filesystems.disks.s3.use_path_style_endpoint', false)),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'firebase_api_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'firebase_auth_domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'firebase_project_id' => ['sometimes', 'string', 'max:120'],
            'firebase_storage_bucket' => ['sometimes', 'nullable', 'string', 'max:255'],
            'firebase_messaging_sender_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'firebase_app_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'google_client_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cashfree_secure_id_enabled' => ['sometimes', 'boolean'],
            'cashfree_secure_id_environment' => ['sometimes', 'in:sandbox,production'],
            'cashfree_secure_id_client_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'cashfree_secure_id_client_secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            'cashfree_secure_id_base_url' => ['sometimes', 'url', 'max:255'],
            'cashfree_secure_id_timeout' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'gst_verification_provider' => ['sometimes', 'in:SANDBOX,CASHFREE,sandbox,cashfree'],
            'kyc_verification_provider' => ['sometimes', 'in:SANDBOX,CASHFREE,sandbox,cashfree'],
            'bank_verification_provider' => ['sometimes', 'in:SANDBOX,CASHFREE,sandbox,cashfree'],
            'sandbox_verification_enabled' => ['sometimes', 'boolean'],
            'sandbox_verification_environment' => ['sometimes', 'in:test,live'],
            'sandbox_verification_api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sandbox_verification_api_secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sandbox_verification_base_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'sandbox_verification_api_version' => ['sometimes', 'string', 'max:40'],
            'sandbox_verification_timeout' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'mail_mailer' => ['sometimes', 'in:smtp,sendmail,log,array'],
            'mail_host' => ['sometimes', 'string', 'max:255'],
            'mail_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mail_password' => ['sometimes', 'nullable', 'string', 'max:500'],
            'mail_encryption' => ['sometimes', 'nullable', 'in:tls,ssl,null'],
            'mail_from_address' => ['sometimes', 'email', 'max:255'],
            'mail_from_name' => ['sometimes', 'string', 'max:120'],
            'pusher_app_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'pusher_app_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pusher_app_secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            'pusher_cluster' => ['sometimes', 'string', 'max:80'],
            'pusher_host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pusher_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'pusher_scheme' => ['sometimes', 'in:http,https'],
            'filesystem_disk' => ['sometimes', 'in:local,public,s3'],
            'aws_access_key_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'aws_secret_access_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'aws_region' => ['sometimes', 'string', 'max:80'],
            'aws_bucket' => ['sometimes', 'nullable', 'string', 'max:255'],
            'aws_endpoint' => ['sometimes', 'nullable', 'url', 'max:255'],
            'aws_use_path_style_endpoints' => ['sometimes', 'boolean'],
        ]);

        foreach (['gst_verification_provider' => 'GSTIN', 'kyc_verification_provider' => 'KYC', 'bank_verification_provider' => 'BANK'] as $selector => $type) {
            if (! array_key_exists($selector, $data)) continue;
            $provider = strtolower((string) $data[$selector]);
            $current = strtolower(GeneralSettings::string($selector, VerificationProviderResolver::SANDBOX));
            if ($provider === $current) continue;
            if (! $this->isProviderConfiguredForUpdate($provider, $data, $type)) {
                return response()->json(['success' => false, 'message' => 'Configure and enable the selected verification provider before activating it.', 'error' => ['code' => 'PROVIDER_NOT_CONFIGURED']], 422);
            }
        }

        $previous = [
            'gst' => GeneralSettings::string('gst_verification_provider', VerificationProviderResolver::SANDBOX),
            'kyc' => GeneralSettings::string('kyc_verification_provider', VerificationProviderResolver::SANDBOX),
            'bank' => GeneralSettings::string('bank_verification_provider', VerificationProviderResolver::SANDBOX),
        ];

        foreach ($data as $key => $value) {
            if (in_array($key, self::SECRET_KEYS, true)) {
                if (filled($value) && ! $this->looksMasked((string) $value)) {
                    GeneralSetting::updateOrCreate(['key' => $key], ['value' => Crypt::encryptString((string) $value)]);
                }
                continue;
            }

            GeneralSetting::updateOrCreate(['key' => $key], ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value]);
        }

        $current = [
            'gst' => GeneralSettings::string('gst_verification_provider', VerificationProviderResolver::SANDBOX),
            'kyc' => GeneralSettings::string('kyc_verification_provider', VerificationProviderResolver::SANDBOX),
            'bank' => GeneralSettings::string('bank_verification_provider', VerificationProviderResolver::SANDBOX),
        ];
        foreach ($current as $type => $provider) {
            if ($previous[$type] !== $provider) {
                \App\Services\AuditLogger::write('VERIFICATION_PROVIDER_CHANGED', 'verification_settings', $type, ['from' => strtoupper($previous[$type]), 'to' => strtoupper($provider)]);
            }
        }

        return $this->show();
    }

    private function isProviderConfiguredForUpdate(string $provider, array $data, string $type): bool
    {
        $provider = strtolower($provider);
        if ($provider === VerificationProviderResolver::SANDBOX) {
            $enabled = array_key_exists('sandbox_verification_enabled', $data)
                ? (bool) $data['sandbox_verification_enabled']
                : GeneralSettings::bool('sandbox_verification_enabled', (bool) config('services.sandbox_verification.enabled', true));
            $key = $this->incomingOrStoredSecret('sandbox_verification_api_key', $data);
            $secret = $this->incomingOrStoredSecret('sandbox_verification_api_secret', $data);
            return $enabled && filled($key) && filled($secret);
        }
        if ($provider === VerificationProviderResolver::CASHFREE) {
            $enabled = array_key_exists('cashfree_secure_id_enabled', $data)
                ? (bool) $data['cashfree_secure_id_enabled']
                : GeneralSettings::bool('cashfree_secure_id_enabled', (bool) config('services.cashfree_secure_id.enabled', false));
            $clientId = $this->incomingOrStoredSecret('cashfree_secure_id_client_id', $data);
            $clientSecret = $this->incomingOrStoredSecret('cashfree_secure_id_client_secret', $data);
            return $enabled && filled($clientId) && filled($clientSecret);
        }
        return false;
    }

    private function incomingOrStoredSecret(string $key, array $data): ?string
    {
        if (array_key_exists($key, $data) && filled($data[$key]) && ! $this->looksMasked((string) $data[$key])) return (string) $data[$key];
        $fallbacks = [
            'cashfree_secure_id_client_id' => config('services.cashfree_secure_id.client_id'),
            'cashfree_secure_id_client_secret' => config('services.cashfree_secure_id.client_secret'),
            'sandbox_verification_api_key' => config('services.sandbox_verification.api_key'),
            'sandbox_verification_api_secret' => config('services.sandbox_verification.api_secret'),
        ];
        return GeneralSettings::secret($key, $fallbacks[$key] ?? null);
    }

    private function looksMasked(string $value): bool
    {
        return str_contains($value, '*') && strlen($value) >= 4;
    }

    private function masked(?string $value): ?string
    {
        if (! filled($value)) return null;
        $value = (string) $value;
        return strlen($value) <= 4 ? str_repeat('*', strlen($value)) : substr($value, 0, 2).str_repeat('*', max(4, strlen($value) - 4)).substr($value, -2);
    }
}
