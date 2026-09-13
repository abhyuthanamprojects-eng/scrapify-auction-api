<?php

namespace App\Services\Verification;

use App\Contracts\BusinessVerificationProviderInterface;
use App\Exceptions\VerificationProviderException;
use App\Services\GeneralSettings;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class SandboxVerificationProvider implements BusinessVerificationProviderInterface
{
    public function key(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox';
    }

    public function isEnabled(): bool
    {
        return GeneralSettings::bool('sandbox_verification_enabled', (bool) config('services.sandbox_verification.enabled', true));
    }

    public function isConfigured(string $verificationType): bool
    {
        return filled($this->apiKey()) && filled($this->apiSecret());
    }

    public function verifyGstin(string $gstin, ?string $businessName = null): NormalizedVerificationResult
    {
        $response = $this->request('POST', '/gst/compliance/public/gstin/verify', ['gstin' => $gstin]);
        $data = $this->unwrap($response);
        $rawStatus = (string) ($data['gst_in_status'] ?? $data['gstin_status'] ?? $data['status'] ?? $data['status_desc'] ?? '');
        $valid = $this->truthy($data['valid'] ?? $data['validGstin'] ?? $data['is_valid'] ?? false);
        $active = $valid || in_array(strtoupper($rawStatus), ['ACTIVE', 'VALID', 'VERIFIED'], true);

        return new NormalizedVerificationResult(
            $active ? 'VERIFIED' : 'FAILED',
            $this->key(),
            $this->reference($response, $data),
            [
                'gstin' => strtoupper((string) ($data['gstin'] ?? $data['GSTIN'] ?? $gstin)),
                'legal_business_name' => $data['legal_name_of_business'] ?? $data['legalName'] ?? $data['legal_name'] ?? $data['legal_name_of_business_as_per_gst'] ?? null,
                'trade_business_name' => $data['trade_name_of_business'] ?? $data['tradeName'] ?? $data['trade_name'] ?? null,
                'constitution_of_business' => $data['constitution_of_business'] ?? $data['constitution'] ?? null,
                'taxpayer_type' => $data['taxpayer_type'] ?? null,
                'gst_registration_status' => $rawStatus ?: null,
                'gst_registration_date' => $data['date_of_registration'] ?? $data['regStartDate'] ?? $data['registration_date'] ?? null,
                'gst_registered_address' => $data['principal_place_split_address'] ?? ($data['principal_place_address'] ?? $data['principal_address'] ?? (($data['stateName'] ?? $data['stateCode'] ?? null) ? array_filter(['state' => $data['stateName'] ?? null, 'state_code' => $data['stateCode'] ?? null]) : null)),
                'business_activities' => $data['nature_of_business_activities'] ?? $data['bussNature'] ?? $data['business_activities'] ?? [],
            ],
            $rawStatus ?: null,
            $active ? null : 'GSTIN_INACTIVE',
            'GSTIN',
        );
    }

    public function verifyPan(string $pan, ?string $name = null, ?string $dateOfBirth = null): NormalizedVerificationResult
    {
        if (! filled($name) || ! filled($dateOfBirth)) {
            throw new VerificationProviderException('PROVIDER_INPUT_REQUIRED', 'Sandbox PAN verification requires the PAN name and date of birth.', 422);
        }

        $response = $this->request('POST', '/kyc/pan/verify', [
            '@entity' => 'in.co.sandbox.kyc.pan_verification.request',
            'pan' => strtoupper($pan),
            'name_as_per_pan' => $name,
            'date_of_birth' => $this->sandboxDate($dateOfBirth),
            'consent' => 'Y',
            'reason' => 'Scrapify business verification onboarding',
        ]);
        $data = $this->unwrap($response);
        $valid = strtoupper((string) ($data['status'] ?? '')) === 'VALID'
            || $this->truthy($data['valid'] ?? $data['is_valid'] ?? false);
        if (array_key_exists('name_as_per_pan_match', $data)) {
            $valid = $valid && $this->truthy($data['name_as_per_pan_match']);
        }
        if (array_key_exists('date_of_birth_match', $data)) {
            $valid = $valid && $this->truthy($data['date_of_birth_match']);
        }

        return new NormalizedVerificationResult(
            $valid ? 'VERIFIED' : 'FAILED',
            $this->key(),
            $this->reference($response, $data),
            [
                'pan' => strtoupper((string) ($data['pan'] ?? $pan)),
                'registered_name' => $data['registered_name'] ?? $data['name_as_per_pan'] ?? null,
                'name_match_score' => is_numeric($data['name_match_score'] ?? null) ? (float) $data['name_match_score'] : null,
                'name_match_result' => $data['name_match_result'] ?? (array_key_exists('name_as_per_pan_match', $data) ? ($this->truthy($data['name_as_per_pan_match']) ? 'MATCH' : 'NO_MATCH') : null),
            ],
            (string) ($data['status'] ?? ''),
            $valid ? null : 'PAN_INVALID',
            'PAN',
        );
    }

    public function verifyBankAccount(string $account, string $ifsc, ?string $name = null, ?string $phone = null): NormalizedVerificationResult
    {
        $path = '/bank/'.rawurlencode($ifsc).'/accounts/'.rawurlencode($account).'/penniless-verify';
        $query = array_filter(['name' => $name, 'mobile' => $phone], static fn ($value) => filled($value));
        $response = $this->request('GET', $path, [], $query);
        $data = $this->unwrap($response);
        $exists = $this->truthy($data['account_exists'] ?? false);

        return new NormalizedVerificationResult(
            $exists ? 'VERIFIED' : 'FAILED',
            $this->key(),
            $this->reference($response, $data),
            [
                'account_status' => $exists ? 'VALID' : 'INVALID',
                'name_at_bank' => $data['name_at_bank'] ?? $data['registered_name'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'branch' => $data['branch'] ?? null,
                'city' => $data['city'] ?? null,
                'name_match_score' => is_numeric($data['name_match_score'] ?? null) ? (float) $data['name_match_score'] : null,
                'name_match_result' => $data['name_match_result'] ?? null,
            ],
            (string) ($data['status'] ?? $data['message'] ?? ''),
            $exists ? null : 'BANK_ACCOUNT_INVALID',
            'BANK',
        );
    }

    private function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        if (! $this->isEnabled()) {
            throw new VerificationProviderException('PROVIDER_DISABLED', 'Sandbox verification is disabled.', 422);
        }
        if (! $this->isConfigured('')) {
            throw new VerificationProviderException('PROVIDER_NOT_CONFIGURED', 'Sandbox verification credentials are not configured.', 422);
        }

        try {
            $token = Cache::remember($this->tokenCacheKey(), now()->addHours(23), fn () => $this->authenticate());
            $send = function (string $accessToken) use ($method, $path, $payload, $query) {
                $request = Http::timeout($this->timeout())->connectTimeout(min(10, $this->timeout()))->acceptJson()->withHeaders([
                    'x-api-key' => $this->apiKey(),
                    'x-api-version' => $this->apiVersion(),
                    'authorization' => $accessToken,
                ]);

                return $method === 'GET'
                    ? $request->get($this->apiBase().$path, $query)
                    : $request->post($this->apiBase().$path, $payload);
            };
            $response = $send($token);
            if ($response->status() === 401) {
                Cache::forget($this->tokenCacheKey());
                $token = $this->authenticate();
                Cache::put($this->tokenCacheKey(), $token, now()->addHours(23));
                $response = $send($token);
            }
        } catch (ConnectionException $exception) {
            throw new VerificationProviderException(
                str_contains(strtolower($exception->getMessage()), 'timed out') ? 'PROVIDER_TIMEOUT' : 'PROVIDER_UNAVAILABLE',
                'Sandbox verification provider is unavailable.',
                503,
            );
        }

        if ($response->failed()) {
            throw new VerificationProviderException($this->failureCode($response->status()), 'Sandbox verification returned an unsuccessful response.', $response->status() >= 500 ? 503 : 422);
        }

        return $response->json() ?: [];
    }

    private function authenticate(): string
    {
        try {
            $response = Http::timeout($this->timeout())->connectTimeout(min(10, $this->timeout()))->acceptJson()->withHeaders([
                'x-api-key' => $this->apiKey(),
                'x-api-secret' => $this->apiSecret(),
                'x-api-version' => $this->apiVersion(),
            ])->post($this->apiBase().'/authenticate');
        } catch (ConnectionException $exception) {
            throw new VerificationProviderException(
                str_contains(strtolower($exception->getMessage()), 'timed out') ? 'PROVIDER_TIMEOUT' : 'PROVIDER_UNAVAILABLE',
                'Sandbox authentication is unavailable.',
                503,
            );
        }

        if ($response->failed()) {
            throw new VerificationProviderException($response->status() >= 500 ? 'PROVIDER_UNAVAILABLE' : 'PROVIDER_AUTH_FAILED', 'Sandbox authentication failed.', $response->status() >= 500 ? 503 : 422);
        }

        $token = $response->json('data.access_token') ?? $response->json('access_token');
        if (! filled($token)) {
            throw new VerificationProviderException('PROVIDER_AUTH_FAILED', 'Sandbox authentication did not return an access token.', 503);
        }

        return (string) $token;
    }

    private function apiBase(): string
    {
        $configured = GeneralSettings::string('sandbox_verification_base_url', '');
        if ($configured !== '') return rtrim($configured, '/');
        return GeneralSettings::string('sandbox_verification_environment', (string) config('services.sandbox_verification.environment', 'test')) === 'live'
            ? 'https://api.sandbox.co.in'
            : 'https://test-api.sandbox.co.in';
    }

    private function apiKey(): ?string
    {
        return GeneralSettings::secret('sandbox_verification_api_key', config('services.sandbox_verification.api_key'));
    }

    private function apiSecret(): ?string
    {
        return GeneralSettings::secret('sandbox_verification_api_secret', config('services.sandbox_verification.api_secret'));
    }

    private function apiVersion(): string
    {
        $version = GeneralSettings::string('sandbox_verification_api_version', (string) config('services.sandbox_verification.api_version', '1.0.0'));
        return $version === '1.0' ? '1.0.0' : $version;
    }

    private function timeout(): int
    {
        return max(5, min(120, GeneralSettings::int('sandbox_verification_timeout', (int) config('services.sandbox_verification.timeout', 30))));
    }

    private function tokenCacheKey(): string
    {
        $environment = GeneralSettings::string('sandbox_verification_environment', (string) config('services.sandbox_verification.environment', 'test'));
        return 'verification:sandbox:token:'.$environment.':'.hash('sha256', (string) $this->apiKey());
    }

    private function sandboxDate(string $dateOfBirth): string
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $dateOfBirth)->format('d/m/Y');
        } catch (\Throwable) {
            return $dateOfBirth;
        }
    }

    private function unwrap(array $response): array
    {
        $data = $response['data'] ?? $response;
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) $data = $data['data'];
        return is_array($data) ? $data : [];
    }

    private function reference(array $response, array $data): ?string
    {
        foreach ([$response['transaction_id'] ?? null, $response['reference_id'] ?? null, $response['request_id'] ?? null, $data['transaction_id'] ?? null, $data['reference_id'] ?? null, $data['utr'] ?? null] as $reference) {
            if (filled($reference)) return (string) $reference;
        }
        return null;
    }

    private function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? in_array(strtoupper((string) $value), ['ACTIVE', 'VALID', 'VERIFIED', 'SUCCESS', '1'], true);
    }

    private function failureCode(int $status): string
    {
        return match (true) {
            $status === 401 => 'PROVIDER_AUTH_FAILED',
            $status === 408 => 'PROVIDER_TIMEOUT',
            $status === 429 => 'RATE_LIMITED',
            $status >= 500 => 'PROVIDER_UNAVAILABLE',
            default => 'VERIFICATION_FAILED',
        };
    }
}
