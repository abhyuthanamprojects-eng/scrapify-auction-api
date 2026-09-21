<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GeneralSetting;
use App\Services\GeneralSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class IntegrationSettingsController extends Controller
{
    private const SECRET_KEYS = [
        'sandbox_verification_api_key',
        'sandbox_verification_api_secret',
        'digilocker_client_id',
        'digilocker_client_secret',
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
            'firebase_project_id' => GeneralSettings::string('firebase_project_id', ''),
            'firebase_storage_bucket' => GeneralSettings::string('firebase_storage_bucket', ''),
            'firebase_messaging_sender_id' => GeneralSettings::string('firebase_messaging_sender_id', ''),
            'firebase_app_id' => GeneralSettings::string('firebase_app_id', ''),
            'google_client_id' => GeneralSettings::string('google_client_id', ''),
            'sandbox_verification_enabled' => GeneralSettings::bool('sandbox_verification_enabled', false),
            'sandbox_verification_environment' => GeneralSettings::string('sandbox_verification_environment', 'test'),
            'sandbox_verification_api_key' => $this->masked(GeneralSettings::secret('sandbox_verification_api_key')),
            'sandbox_verification_api_secret' => $this->masked(GeneralSettings::secret('sandbox_verification_api_secret')),
            'sandbox_verification_base_url' => GeneralSettings::string('sandbox_verification_base_url', ''),
            'sandbox_verification_api_version' => GeneralSettings::string('sandbox_verification_api_version', '1.0.0'),
            'sandbox_verification_timeout' => GeneralSettings::int('sandbox_verification_timeout', 30),
            'digilocker_enabled' => GeneralSettings::bool('digilocker_enabled', false),
            'digilocker_environment' => GeneralSettings::string('digilocker_environment', 'sandbox'),
            'digilocker_client_id' => $this->masked(GeneralSettings::secret('digilocker_client_id')),
            'digilocker_client_secret' => $this->masked(GeneralSettings::secret('digilocker_client_secret')),
            'digilocker_redirect_uri' => GeneralSettings::string('digilocker_redirect_uri', ''),
            'digilocker_scopes' => GeneralSettings::string('digilocker_scopes', 'openid'),
            'digilocker_timeout' => GeneralSettings::int('digilocker_timeout', 30),
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
            'sandbox_verification_enabled' => ['sometimes', 'boolean'],
            'sandbox_verification_environment' => ['sometimes', 'in:test,live'],
            'sandbox_verification_api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sandbox_verification_api_secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            'sandbox_verification_base_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'sandbox_verification_api_version' => ['sometimes', 'string', 'max:40'],
            'sandbox_verification_timeout' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'digilocker_enabled' => ['sometimes', 'boolean'],
            'digilocker_environment' => ['sometimes', 'in:sandbox,production'],
            'digilocker_client_id' => ['sometimes', 'nullable', 'string', 'max:500'],
            'digilocker_client_secret' => ['sometimes', 'nullable', 'string', 'max:500'],
            'digilocker_redirect_uri' => ['sometimes', 'nullable', 'url', 'max:500'],
            'digilocker_scopes' => ['sometimes', 'string', 'max:255'],
            'digilocker_timeout' => ['sometimes', 'integer', 'min:5', 'max:120'],
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

        foreach ($data as $key => $value) {
            if (in_array($key, self::SECRET_KEYS, true)) {
                if (filled($value) && ! $this->looksMasked((string) $value)) {
                    GeneralSetting::updateOrCreate(['key' => $key], ['value' => Crypt::encryptString((string) $value)]);
                }
                continue;
            }

            GeneralSetting::updateOrCreate(['key' => $key], ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value]);
        }

        return $this->show();
    }

    private function incomingOrStoredSecret(string $key, array $data): ?string
    {
        if (array_key_exists($key, $data) && filled($data[$key]) && ! $this->looksMasked((string) $data[$key])) return (string) $data[$key];
        return GeneralSettings::secret($key);
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
