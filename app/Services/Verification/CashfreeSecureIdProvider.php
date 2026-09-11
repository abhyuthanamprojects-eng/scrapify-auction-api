<?php

namespace App\Services\Verification;

use App\Contracts\BusinessVerificationProviderInterface;
use App\Services\GeneralSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CashfreeSecureIdProvider implements BusinessVerificationProviderInterface
{
    private function request(string $path, array $payload): array
    {
        $enabled = GeneralSettings::bool('cashfree_secure_id_enabled', (bool) config('services.cashfree_secure_id.enabled'));
        $clientId = GeneralSettings::secret('cashfree_secure_id_client_id', config('services.cashfree_secure_id.client_id'));
        $clientSecret = GeneralSettings::secret('cashfree_secure_id_client_secret', config('services.cashfree_secure_id.client_secret'));
        $baseUrl = GeneralSettings::string('cashfree_secure_id_base_url', (string) config('services.cashfree_secure_id.base_url'));
        $timeout = GeneralSettings::int('cashfree_secure_id_timeout', (int) config('services.cashfree_secure_id.timeout', 30));
        if (! $enabled || ! $clientId || ! $clientSecret) {
            throw new RuntimeException('PROVIDER_NOT_CONFIGURED');
        }

        $response = Http::timeout($timeout)
            ->acceptJson()
            ->withHeaders(['x-client-id' => $clientId, 'x-client-secret' => $clientSecret])
            ->post(rtrim($baseUrl, '/').$path, $payload);

        if ($response->failed()) throw new RuntimeException('PROVIDER_HTTP_'.$response->status());
        return $response->json() ?: [];
    }

    public function verifyGstin(string $gstin, ?string $businessName = null): array
    {
        return $this->request('/gstin', array_filter(['GSTIN' => $gstin, 'business_name' => $businessName]));
    }

    public function verifyBankAccount(string $account, string $ifsc, ?string $name = null, ?string $phone = null): array
    {
        return $this->request('/bank-account/sync', array_filter(['bank_account' => $account, 'ifsc' => $ifsc, 'name' => $name, 'phone' => $phone]));
    }
}
