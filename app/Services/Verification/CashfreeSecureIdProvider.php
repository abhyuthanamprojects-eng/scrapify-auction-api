<?php

namespace App\Services\Verification;

use App\Contracts\BusinessVerificationProviderInterface;
use App\Exceptions\VerificationProviderException;
use App\Services\GeneralSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class CashfreeSecureIdProvider implements BusinessVerificationProviderInterface
{
    public function key(): string
    {
        return 'cashfree';
    }

    public function label(): string
    {
        return 'Cashfree Secure ID';
    }

    public function isEnabled(): bool
    {
        return GeneralSettings::bool('cashfree_secure_id_enabled', (bool) config('services.cashfree_secure_id.enabled'));
    }

    public function isConfigured(string $verificationType): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret());
    }

    private function request(string $path, array $payload): array
    {
        if (! $this->isEnabled()) {
            throw new VerificationProviderException('PROVIDER_DISABLED', 'Cashfree verification is disabled.', 422);
        }
        if (! $this->isConfigured('')) {
            throw new VerificationProviderException('PROVIDER_NOT_CONFIGURED', 'Cashfree verification credentials are not configured.', 422);
        }

        try {
            $response = Http::timeout($this->timeout())
                ->acceptJson()
                ->withHeaders(['x-client-id' => $this->clientId(), 'x-client-secret' => $this->clientSecret()])
                ->post($this->baseUrl().$path, $payload);
        } catch (ConnectionException $exception) {
            throw new VerificationProviderException(
                str_contains(strtolower($exception->getMessage()), 'timed out') ? 'PROVIDER_TIMEOUT' : 'PROVIDER_UNAVAILABLE',
                'Cashfree verification provider is unavailable.',
                503,
            );
        }

        if ($response->failed()) throw new VerificationProviderException($this->failureCode($response->status()), 'Cashfree verification returned an unsuccessful response.', $response->status() >= 500 ? 503 : 422);
        return $response->json() ?: [];
    }

    public function verifyGstin(string $gstin, ?string $businessName = null): NormalizedVerificationResult
    {
        $response = $this->request('/gstin', array_filter(['GSTIN' => $gstin, 'business_name' => $businessName]));
        $active = (bool) ($response['valid'] ?? false) && strtoupper((string) ($response['gst_in_status'] ?? '')) === 'ACTIVE';
        return new NormalizedVerificationResult($active ? 'VERIFIED' : 'FAILED', $this->key(), $this->reference($response), [
            'gstin' => strtoupper((string) ($response['GSTIN'] ?? $gstin)),
            'legal_business_name' => $response['legal_name_of_business'] ?? null,
            'trade_business_name' => $response['trade_name_of_business'] ?? null,
            'constitution_of_business' => $response['constitution_of_business'] ?? null,
            'taxpayer_type' => $response['taxpayer_type'] ?? null,
            'gst_registration_status' => $response['gst_in_status'] ?? null,
            'gst_registration_date' => $response['date_of_registration'] ?? null,
            'gst_registered_address' => $response['principal_place_split_address'] ?? ['address' => $response['principal_place_address'] ?? null],
            'business_activities' => $response['nature_of_business_activities'] ?? [],
        ], (string) ($response['gst_in_status'] ?? ''), $active ? null : 'GSTIN_INACTIVE', 'GSTIN');
    }

    public function verifyPan(string $pan, ?string $name = null, ?string $dateOfBirth = null): NormalizedVerificationResult
    {
        $response = $this->request('/pan', array_filter(['pan' => strtoupper($pan), 'name' => $name]));
        $valid = (bool) ($response['valid'] ?? false);
        return new NormalizedVerificationResult($valid ? 'VERIFIED' : 'FAILED', $this->key(), $this->reference($response), [
            'pan' => strtoupper((string) ($response['pan'] ?? $pan)),
            'registered_name' => $response['registered_name'] ?? null,
            'name_match_score' => is_numeric($response['name_match_score'] ?? null) ? (float) $response['name_match_score'] : null,
            'name_match_result' => $response['name_match_result'] ?? null,
        ], (string) ($response['status'] ?? ''), $valid ? null : 'PAN_INVALID', 'PAN');
    }

    public function verifyBankAccount(string $account, string $ifsc, ?string $name = null, ?string $phone = null): NormalizedVerificationResult
    {
        $response = $this->request('/bank-account/sync', array_filter(['bank_account' => $account, 'ifsc' => $ifsc, 'name' => $name, 'phone' => $phone]));
        $valid = strtoupper((string) ($response['account_status'] ?? '')) === 'VALID';
        return new NormalizedVerificationResult($valid ? 'VERIFIED' : 'FAILED', $this->key(), $this->reference($response), [
            'account_status' => $response['account_status'] ?? null,
            'name_at_bank' => $response['name_at_bank'] ?? null,
            'bank_name' => $response['bank_name'] ?? null,
            'branch' => $response['branch'] ?? null,
            'city' => $response['city'] ?? null,
            'name_match_score' => is_numeric($response['name_match_score'] ?? null) ? (float) $response['name_match_score'] : null,
            'name_match_result' => $response['name_match_result'] ?? null,
        ], (string) ($response['account_status'] ?? ''), $valid ? null : 'BANK_ACCOUNT_INVALID', 'BANK');
    }

    private function clientId(): ?string
    {
        return GeneralSettings::secret('cashfree_secure_id_client_id', config('services.cashfree_secure_id.client_id'));
    }

    private function clientSecret(): ?string
    {
        return GeneralSettings::secret('cashfree_secure_id_client_secret', config('services.cashfree_secure_id.client_secret'));
    }

    private function baseUrl(): string
    {
        return rtrim(GeneralSettings::string('cashfree_secure_id_base_url', (string) config('services.cashfree_secure_id.base_url')), '/');
    }

    private function timeout(): int
    {
        return max(5, min(120, GeneralSettings::int('cashfree_secure_id_timeout', (int) config('services.cashfree_secure_id.timeout', 30))));
    }

    private function reference(array $response): ?string
    {
        foreach (['reference_id', 'referenceId', 'transaction_id', 'request_id'] as $key) {
            if (filled($response[$key] ?? null)) return (string) $response[$key];
        }
        return null;
    }

    private function failureCode(int $status): string
    {
        return match (true) {
            $status === 408 => 'PROVIDER_TIMEOUT',
            $status === 429 => 'RATE_LIMITED',
            $status >= 500 => 'PROVIDER_UNAVAILABLE',
            default => 'VERIFICATION_FAILED',
        };
    }
}
