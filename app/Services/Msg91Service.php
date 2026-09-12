<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Msg91Service
{
    private const BASE_URL = 'https://control.msg91.com/api/v5';

    private string $authKey;
    private string $templateId;
    private string $senderId;
    private string $countryCode;

    public function __construct()
    {
        $this->authKey = (string) GeneralSettings::secret('msg91_auth_key', config('services.msg91.auth_key'));
        $this->templateId = GeneralSettings::string('msg91_otp_template_id', (string) config('services.msg91.otp_template_id', ''));
        $this->senderId = GeneralSettings::string('msg91_sender_id', (string) config('services.msg91.sender_id', ''));
        $this->countryCode = GeneralSettings::string('msg91_country_code', (string) config('services.msg91.country_code', '91'));
    }

    public function isConfigured(): bool
    {
        return trim($this->authKey) !== ''
            && trim($this->templateId) !== ''
            && trim($this->senderId) !== ''
            && trim($this->countryCode) !== '';
    }

    public function sendOtp(string $phone): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'code' => 'OTP_PROVIDER_NOT_CONFIGURED', 'message' => 'OTP verification is temporarily unavailable.'];
        }

        try {
            $url = self::BASE_URL . '/otp?' . http_build_query([
                'template_id' => trim($this->templateId),
                'mobile' => $this->formatPhone($phone),
                'sender' => trim($this->senderId),
            ]);

            $response = Http::timeout(10)->withHeaders([
                'authkey' => trim($this->authKey),
                'Content-Type' => 'application/json',
            ])->post($url, []);

            $body = $response->json();
            $providerType = is_array($body) ? ($body['type'] ?? null) : null;
            $providerMessage = is_array($body) ? ($body['message'] ?? null) : null;
            $requestId = is_array($body) ? ($body['request_id'] ?? $body['reqId'] ?? null) : null;
            Log::info('MSG91 OTP send completed', [
                'status' => $response->status(),
                'type' => $providerType,
                'message' => is_string($providerMessage) ? $providerMessage : null,
                'request_id' => is_string($requestId) ? $requestId : null,
            ]);

            return [
                'success' => $response->successful() && $providerType === 'success',
                'code' => $response->successful() ? null : 'OTP_PROVIDER_ERROR',
                'request_id' => is_string($requestId) ? $requestId : null,
                'provider_message' => is_string($providerMessage) ? $providerMessage : null,
                'message' => ($response->successful() && $providerType === 'success')
                    ? 'OTP sent successfully.'
                    : (is_string($providerMessage) && $providerMessage !== ''
                        ? $providerMessage
                        : 'We could not send the OTP right now. Please try again.'),
            ];
        } catch (\Throwable $exception) {
            Log::error('MSG91 OTP send failed', ['error_class' => get_class($exception)]);
            return ['success' => false, 'code' => 'OTP_PROVIDER_UNAVAILABLE', 'message' => 'We could not send the OTP right now. Please try again.'];
        }
    }

    public function verifyOtp(string $phone, string $code): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::timeout(10)->withHeaders(['authkey' => trim($this->authKey)])
                ->get(self::BASE_URL . '/otp/verify', [
                    'mobile' => $this->formatPhone($phone),
                    'otp' => $code,
                ]);

            Log::info('MSG91 OTP verification completed', ['status' => $response->status()]);
            return $response->successful() && ($response->json('type') ?? null) === 'success';
        } catch (\Throwable $exception) {
            Log::error('MSG91 OTP verification failed', ['error_class' => get_class($exception)]);
            return false;
        }
    }

    public function resendOtp(string $phone): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'code' => 'OTP_PROVIDER_NOT_CONFIGURED', 'message' => 'OTP verification is temporarily unavailable.'];
        }

        try {
            $response = Http::timeout(10)->withHeaders([
                'authkey' => trim($this->authKey),
                'Content-Type' => 'application/json',
            ])->post(self::BASE_URL . '/otp/retry', [
                'mobile' => $this->formatPhone($phone),
                'retrytype' => 'text',
            ]);

            Log::info('MSG91 OTP resend completed', ['status' => $response->status()]);
            $success = $response->successful() && ($response->json('type') ?? null) === 'success';
            return [
                'success' => $success,
                'code' => $success ? null : 'OTP_PROVIDER_ERROR',
                'message' => $success ? 'OTP resent successfully.' : 'We could not resend the OTP right now. Please try again.',
            ];
        } catch (\Throwable $exception) {
            Log::error('MSG91 OTP resend failed', ['error_class' => get_class($exception)]);
            return ['success' => false, 'code' => 'OTP_PROVIDER_UNAVAILABLE', 'message' => 'We could not resend the OTP right now. Please try again.'];
        }
    }

    private function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '91') && strlen($digits) === 12) {
            return $digits;
        }
        if (strlen($digits) === 10) {
            return $this->countryCode . $digits;
        }
        return $digits;
    }
}
