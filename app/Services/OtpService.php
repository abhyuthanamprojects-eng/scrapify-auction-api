<?php

namespace App\Services;

use App\Models\Otp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public function __construct(private readonly Msg91Service $msg91) {}

    public function channel(string $identifier): string
    {
        return filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'sms';
    }

    public function otpLength(string $identifier): int
    {
        $key = $this->channel($identifier) === 'email' ? 'email_otp_length' : 'msg91_otp_length';
        $default = $this->channel($identifier) === 'email' ? 6 : 4;

        return min(8, max(4, GeneralSettings::int($key, $default)));
    }

    public function normalizeIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);
        if ($this->channel($identifier) === 'email') {
            return strtolower($identifier);
        }

        $digits = preg_replace('/\D+/', '', $identifier) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        if (! preg_match('/^[6-9]\d{9}$/', $digits)) {
            return $identifier;
        }
        return $digits;
    }

    public function request(string $identifier, string $purpose, bool $applyLimits = true): array
    {
        $identifier = $this->normalizeIdentifier($identifier);
        $channel = $this->channel($identifier);
        if ($applyLimits) {
            $this->enforceLimits($identifier, false);
        }

        $testMode = (bool) config('services.msg91.otp_test_mode', false) && app()->environment(['local', 'testing']);
        $length = $this->otpLength($identifier);
        $code = $testMode ? str_repeat('1', $length) : (string) random_int(10 ** ($length - 1), (10 ** $length) - 1);

        if ($channel === 'sms' && ! $testMode) {
            if (! GeneralSettings::bool('msg91_enabled', true)) {
                return ['success' => false, 'code' => 'OTP_PROVIDER_DISABLED', 'message' => 'SMS OTP verification is temporarily unavailable.'];
            }
            $result = $this->msg91->sendOtp($identifier);
            if (! $result['success']) {
                return $result;
            }
            $storedCode = 'managed';
        } elseif ($channel === 'email') {
            if (! GeneralSettings::bool('email_enabled', true)) {
                return ['success' => false, 'code' => 'EMAIL_PROVIDER_DISABLED', 'message' => 'Email OTP verification is temporarily unavailable.'];
            }
            $fromAddress = trim(GeneralSettings::string('email_from_address', (string) config('mail.from.address', '')));
            if (app()->environment('production') && ! filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
                return ['success' => false, 'code' => 'EMAIL_FROM_ADDRESS_INVALID', 'message' => 'Email OTP delivery is not configured with a valid verified sender address.'];
            }
            if (! app()->environment(['local', 'testing']) && in_array(config('mail.default'), ['log', 'array'], true)) {
                return ['success' => false, 'code' => 'EMAIL_PROVIDER_NOT_CONFIGURED', 'message' => 'Email OTP verification is not configured yet.'];
            }
            try {
                config([
                    'mail.default' => GeneralSettings::string('mail_mailer', (string) config('mail.default', 'smtp')),
                    'mail.mailers.smtp.host' => GeneralSettings::string('mail_host', (string) config('mail.mailers.smtp.host', '')),
                    'mail.mailers.smtp.port' => GeneralSettings::int('mail_port', (int) config('mail.mailers.smtp.port', 587)),
                    'mail.mailers.smtp.username' => GeneralSettings::secret('mail_username', config('mail.mailers.smtp.username')),
                    'mail.mailers.smtp.password' => GeneralSettings::secret('mail_password', config('mail.mailers.smtp.password')),
                    'mail.mailers.smtp.scheme' => GeneralSettings::string('mail_encryption', (string) config('mail.mailers.smtp.scheme', 'tls')),
                    'mail.from.address' => $fromAddress,
                    'mail.from.name' => GeneralSettings::string('mail_from_name', (string) config('mail.from.name', 'Scrapify Auctions')),
                ]);
                $minutes = GeneralSettings::int('otp_expiry_minutes', 5);
                $body = str_replace([':code', ':minutes'], [$code, (string) $minutes], GeneralSettings::string('email_otp_template', 'Your Scrapify Auctions verification code is :code. It expires in :minutes minutes.'));
                Mail::raw($body, function ($message) use ($identifier): void {
                    $message->to($identifier)->subject('Scrapify Auctions verification code');
                    $from = GeneralSettings::string('email_from_address', (string) config('mail.from.address', ''));
                    $name = GeneralSettings::string('email_from_name', (string) config('mail.from.name', 'Scrapify Auctions'));
                    if (filter_var($from, FILTER_VALIDATE_EMAIL)) {
                        $message->from($from, $name);
                    }
                });
            } catch (\Throwable) {
                return ['success' => false, 'code' => 'EMAIL_PROVIDER_UNAVAILABLE', 'message' => 'We could not send the email OTP right now. Please try again.'];
            }
            $storedCode = Hash::make($code);
        } else {
            $storedCode = Hash::make($code);
        }

        Otp::where('identifier', $identifier)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        $otp = Otp::create([
            'identifier' => $identifier,
            'channel' => $channel,
            'purpose' => $purpose,
            'code' => $storedCode,
            'expires_at' => now()->addMinutes(max(1, GeneralSettings::int('otp_expiry_minutes', 5))),
        ]);

        return [
            'success' => true,
            'otp_length' => $length,
            'expires_at' => $otp->expires_at->toIso8601String(),
            'resend_after' => max(1, GeneralSettings::int('otp_resend_cooldown_seconds', 30)),
        ];
    }

    public function verify(string $identifier, string $purpose, string $code): bool
    {
        $identifier = $this->normalizeIdentifier($identifier);
        $otp = Otp::where('identifier', $identifier)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp || ! $otp->isUsable()) {
            return false;
        }

        $maxAttempts = max(1, GeneralSettings::int('otp_max_verification_attempts', 5));
        if ($otp->attempts >= $maxAttempts) {
            $otp->update(['consumed_at' => now()]);
            return false;
        }

        $valid = $otp->channel === 'sms' && $otp->code === 'managed'
            ? $this->msg91->verifyOtp($identifier, $code)
            : Hash::check($code, $otp->code);

        $otp->increment('attempts');
        if ($valid) {
            $otp->update(['consumed_at' => now()]);
        } elseif ($otp->attempts + 1 >= $maxAttempts) {
            $otp->update(['consumed_at' => now()]);
        }

        return $valid;
    }

    public function resend(string $identifier, string $purpose): array
    {
        $identifier = $this->normalizeIdentifier($identifier);
        $this->enforceLimits($identifier, true);
        if ($this->channel($identifier) === 'sms' && ! ((bool) config('services.msg91.otp_test_mode', false) && app()->environment(['local', 'testing']))) {
            if (! GeneralSettings::bool('msg91_enabled', true)) {
                return ['success' => false, 'code' => 'OTP_PROVIDER_DISABLED', 'message' => 'SMS OTP verification is temporarily unavailable.'];
            }
            $result = $this->msg91->resendOtp($identifier);
            if (! $result['success']) {
                return $result;
            }

            Otp::where('identifier', $identifier)->where('purpose', $purpose)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $otp = Otp::create([
                'identifier' => $identifier,
                'channel' => 'sms',
                'purpose' => $purpose,
                'code' => 'managed',
                'expires_at' => now()->addMinutes(max(1, GeneralSettings::int('otp_expiry_minutes', 5))),
            ]);

            return [
                'success' => true,
                'expires_at' => $otp->expires_at->toIso8601String(),
                'resend_after' => max(1, GeneralSettings::int('otp_resend_cooldown_seconds', 30)),
            ];
        }

        return $this->request($identifier, $purpose, false);
    }

    public function hasRecentVerification(string $identifier, string $channel, string $purpose): bool
    {
        $identifier = $this->normalizeIdentifier($identifier);

        return Otp::where('identifier', $identifier)
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->whereNotNull('consumed_at')
            ->where('consumed_at', '>=', now()->subMinutes(30))
            ->exists();
    }

    private function enforceLimits(string $identifier, bool $resend): void
    {
        $cooldown = max(1, GeneralSettings::int('otp_resend_cooldown_seconds', 30));
        $perHour = max(1, GeneralSettings::int('otp_rate_limit_per_hour', 10));
        $maxResends = max(1, GeneralSettings::int('otp_max_resend_attempts', 3));
        $subject = hash('sha256', strtolower($identifier));
        $prefix = $resend ? 'resend' : 'send';
        $cooldownKey = "otp:{$prefix}:cooldown:{$subject}";
        if (cache()->has($cooldownKey)) {
            abort(429, 'Too many OTP requests. Please try again later.');
        }

        $hourKey = "otp:hour:{$subject}";
        $count = (int) cache()->get($hourKey, 0);
        if ($count >= $perHour) {
            abort(429, 'Too many OTP requests. Please try again later.');
        }

        if ($resend) {
            $resendHourKey = "otp:resend:hour:{$subject}";
            $resendCount = (int) cache()->get($resendHourKey, 0);
            if ($resendCount >= $maxResends) {
                abort(429, 'Maximum OTP resends reached. Please try again later.');
            }
            cache()->put($resendHourKey, $resendCount + 1, now()->addHour());
        }

        cache()->put($hourKey, $count + 1, now()->addHour());
        cache()->put($cooldownKey, true, now()->addSeconds($cooldown));
    }
}
