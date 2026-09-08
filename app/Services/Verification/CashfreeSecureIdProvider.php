<?php

namespace App\Services\Verification;

use App\Contracts\BusinessVerificationProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class CashfreeSecureIdProvider implements BusinessVerificationProviderInterface
{
    private function request(string $path, array $payload): array
    {
        if (! config('services.cashfree_secure_id.enabled') || ! config('services.cashfree_secure_id.client_id') || ! config('services.cashfree_secure_id.client_secret')) {
            throw new RuntimeException('PROVIDER_NOT_CONFIGURED');
        }

        $response = Http::timeout((int) config('services.cashfree_secure_id.timeout', 30))
            ->acceptJson()
            ->withHeaders(['x-client-id' => config('services.cashfree_secure_id.client_id'), 'x-client-secret' => config('services.cashfree_secure_id.client_secret')])
            ->post(rtrim(config('services.cashfree_secure_id.base_url'), '/').$path, $payload);

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
