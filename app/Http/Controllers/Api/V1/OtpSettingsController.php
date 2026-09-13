<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GeneralSetting;
use App\Models\Otp;
use App\Rules\IndianMobileNumber;
use App\Services\AuditLogger;
use App\Services\GeneralSettings;
use App\Services\Msg91Service;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class OtpSettingsController extends Controller
{
    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'channel' => ['sometimes', 'nullable', 'in:sms,email'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Otp::query()
            ->where('created_at', '>=', now()->subDays(90))
            ->latest('created_at')
            ->latest('id');

        if (filled($data['identifier'] ?? null)) {
            $identifier = trim((string) $data['identifier']);
            $query->where('identifier', 'like', '%'.$identifier.'%');
        }

        if (filled($data['channel'] ?? null)) {
            $query->where('channel', $data['channel']);
        }

        $page = $query->paginate((int) ($data['per_page'] ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (Otp $otp) => [
                'id' => $otp->id,
                'identifier' => $this->maskIdentifier($otp->identifier),
                'channel' => $otp->channel,
                'purpose' => $otp->purpose,
                'status' => $otp->consumed_at !== null
                    ? 'used'
                    : ($otp->expires_at?->isPast() ? 'expired' : 'active'),
                'attempts' => $otp->attempts,
                'created_at' => $otp->created_at?->toIso8601String(),
                'expires_at' => $otp->expires_at?->toIso8601String(),
                'consumed_at' => $otp->consumed_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'retention_days' => 90,
            ],
        ]);
    }

    public function show(Msg91Service $msg91): JsonResponse
    {
        $authKey = GeneralSettings::secret('msg91_auth_key', config('services.msg91.auth_key'));

        return response()->json([
            'email_enabled' => GeneralSettings::bool('email_enabled', true),
            'email_from_name' => GeneralSettings::string('email_from_name', (string) config('mail.from.name', 'Scrapify Auctions')),
            'email_from_address' => GeneralSettings::string('email_from_address', (string) config('mail.from.address', '')),
            'email_otp_template' => GeneralSettings::string('email_otp_template', 'Your Scrapify Auctions verification code is :code. It expires in :minutes minutes.'),
            'msg91_enabled' => GeneralSettings::bool('msg91_enabled', true),
            'msg91_auth_key' => $this->maskSecret($authKey),
            'msg91_configured' => $msg91->isConfigured(),
            'msg91_otp_template_id' => GeneralSettings::string('msg91_otp_template_id', (string) config('services.msg91.otp_template_id', '')),
            'msg91_sms_template_id' => GeneralSettings::string('msg91_sms_template_id', (string) config('services.msg91.sms_template_id', '')),
            'msg91_sender_id' => GeneralSettings::string('msg91_sender_id', (string) config('services.msg91.sender_id', '')),
            'msg91_country_code' => GeneralSettings::string('msg91_country_code', (string) config('services.msg91.country_code', '91')),
            'msg91_otp_length' => 4,
            'email_otp_length' => 4,
            'otp_expiry_minutes' => GeneralSettings::int('otp_expiry_minutes', 5),
            'otp_resend_cooldown_seconds' => GeneralSettings::int('otp_resend_cooldown_seconds', 30),
            'otp_max_verification_attempts' => GeneralSettings::int('otp_max_verification_attempts', 5),
            'otp_max_resend_attempts' => GeneralSettings::int('otp_max_resend_attempts', 3),
            'otp_rate_limit_per_hour' => GeneralSettings::int('otp_rate_limit_per_hour', 10),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email_enabled' => ['sometimes', 'boolean'],
            'email_from_name' => ['sometimes', 'string', 'max:120'],
            'email_from_address' => ['sometimes', 'email', 'max:255'],
            'email_otp_template' => ['sometimes', 'string', 'max:1000'],
            'msg91_enabled' => ['sometimes', 'boolean'],
            'msg91_auth_key' => ['sometimes', 'nullable', 'string', 'max:500'],
            'msg91_otp_template_id' => ['sometimes', 'required_if:msg91_enabled,true', 'string', 'max:120'],
            'msg91_sms_template_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'msg91_sender_id' => ['sometimes', 'required_if:msg91_enabled,true', 'string', 'max:30'],
            'msg91_country_code' => ['sometimes', 'required_if:msg91_enabled,true', 'string', 'max:8'],
            'msg91_otp_length' => ['sometimes', 'integer', 'in:4'],
            'email_otp_length' => ['sometimes', 'integer', 'in:4'],
            'otp_expiry_minutes' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'otp_resend_cooldown_seconds' => ['sometimes', 'integer', 'min:10', 'max:3600'],
            'otp_max_verification_attempts' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'otp_max_resend_attempts' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'otp_rate_limit_per_hour' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        foreach (['email_enabled', 'email_from_name', 'email_from_address', 'email_otp_template', 'msg91_enabled', 'msg91_otp_template_id', 'msg91_sms_template_id', 'msg91_sender_id', 'msg91_country_code', 'msg91_otp_length', 'email_otp_length', 'otp_expiry_minutes', 'otp_resend_cooldown_seconds', 'otp_max_verification_attempts', 'otp_max_resend_attempts', 'otp_rate_limit_per_hour'] as $key) {
            if (array_key_exists($key, $data)) {
                GeneralSetting::updateOrCreate(['key' => $key], ['value' => (string) $data[$key]]);
            }
        }

        if (array_key_exists('msg91_auth_key', $data) && filled($data['msg91_auth_key'])) {
            GeneralSetting::updateOrCreate(
                ['key' => 'msg91_auth_key'],
                ['value' => Crypt::encryptString($data['msg91_auth_key'])]
            );
        }

        AuditLogger::write('Updated OTP provider settings', 'GeneralSetting', 'msg91', [
            'changed_keys' => array_values(array_diff(array_keys($data), ['msg91_auth_key'])),
        ]);

        return $this->show(app(Msg91Service::class));
    }

    public function sendTest(Request $request, Msg91Service $msg91): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', new IndianMobileNumber()],
        ]);

        if (! GeneralSettings::bool('msg91_enabled', true)) {
            return response()->json(['message' => 'MSG91 OTP is disabled.', 'error' => ['code' => 'OTP_PROVIDER_DISABLED']], 422);
        }

        if (! $msg91->isConfigured()) {
            return response()->json([
                'message' => 'MSG91 is not configured. Save the Auth Key, OTP Template ID, Sender ID, and Country Code first.',
                'error' => ['code' => 'OTP_PROVIDER_NOT_CONFIGURED'],
            ], 422);
        }

        $result = $msg91->sendOtp($data['phone']);
        AuditLogger::write('Tested OTP provider', 'GeneralSetting', 'msg91', [
            'success' => $result['success'],
            'code' => $result['code'] ?? null,
        ]);

        if (! $result['success']) {
            return response()->json(['message' => $result['message'], 'error' => ['code' => $result['code'] ?? 'OTP_PROVIDER_ERROR']], 422);
        }

        return response()->json([
            'message' => 'Test OTP request accepted by MSG91. Delivery may take a moment.',
            'provider_request_id' => $result['request_id'] ?? null,
            'provider_message' => $result['provider_message'] ?? null,
        ]);
    }

    public function sendEmailTest(Request $request, OtpService $otpService): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        if (! GeneralSettings::bool('email_enabled', true)) {
            return response()->json([
                'message' => 'Email OTP is disabled.',
                'error' => ['code' => 'EMAIL_PROVIDER_DISABLED'],
            ], 422);
        }

        $from = trim(GeneralSettings::string('email_from_address', ''));
        if (! filter_var($from, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'message' => 'Email From Address is missing or invalid. Set a verified Brevo sender address and save OTP Configuration.',
                'error' => ['code' => 'EMAIL_FROM_ADDRESS_INVALID'],
            ], 422);
        }

        if (app()->environment('production')) {
            $mailer = strtolower(trim(GeneralSettings::string('mail_mailer', (string) config('mail.default', 'smtp'))));
            $host = trim(GeneralSettings::string('mail_host', (string) config('mail.mailers.smtp.host', '')));
            $username = trim((string) GeneralSettings::secret('mail_username', config('mail.mailers.smtp.username')));
            $password = trim((string) GeneralSettings::secret('mail_password', config('mail.mailers.smtp.password')));

            if ($mailer !== 'smtp') {
                return response()->json([
                    'message' => 'Production email delivery is not using SMTP. Select SMTP and save the Brevo settings.',
                    'error' => ['code' => 'EMAIL_SMTP_NOT_ENABLED'],
                ], 422);
            }

            if ($host === '' || in_array(strtolower($host), ['mailpit', 'localhost', '127.0.0.1'], true)) {
                return response()->json([
                    'message' => 'Production is still using the local Mailpit SMTP host. Set SMTP Host to smtp-relay.brevo.com and save it.',
                    'error' => ['code' => 'EMAIL_SMTP_LOCAL_HOST'],
                ], 422);
            }

            if ($username === '' || $password === '') {
                return response()->json([
                    'message' => 'Brevo SMTP credentials are missing. Enter the Brevo SMTP login and SMTP key, then save the settings.',
                    'error' => ['code' => 'EMAIL_SMTP_CREDENTIALS_MISSING'],
                ], 422);
            }
        }

        // This is an admin-only delivery check. Keep it separate from the
        // public registration OTP purpose and do not consume a customer's
        // cooldown or hourly OTP quota while testing SMTP.
        $result = $otpService->request($data['email'], 'admin_email_test', false);
        AuditLogger::write('Tested email OTP provider', 'GeneralSetting', 'smtp', [
            'success' => $result['success'],
            'code' => $result['code'] ?? null,
        ]);

        if (! $result['success']) {
            return response()->json([
                'message' => $result['message'],
                'error' => ['code' => $result['code'] ?? 'EMAIL_PROVIDER_ERROR'],
            ], 422);
        }

        return response()->json([
            'message' => 'Test email OTP sent successfully. Check the inbox and spam folder.',
            'destination' => $data['email'],
            'expires_at' => $result['expires_at'],
        ]);
    }

    private function maskSecret(?string $secret): ?string
    {
        if (! $secret) {
            return null;
        }

        $length = strlen($secret);
        return $length <= 4 ? str_repeat('*', $length) : substr($secret, 0, 2) . str_repeat('*', max(4, $length - 4)) . substr($secret, -2);
    }

    private function maskIdentifier(string $identifier): string
    {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            [$local, $domain] = explode('@', $identifier, 2);
            $visible = strlen($local) <= 2 ? substr($local, 0, 1) : substr($local, 0, 2);
            return $visible.str_repeat('*', max(2, strlen($local) - strlen($visible))).'@'.$domain;
        }

        $digits = preg_replace('/\D+/', '', $identifier) ?? $identifier;
        return strlen($digits) > 4 ? str_repeat('*', strlen($digits) - 4).substr($digits, -4) : '****';
    }
}
