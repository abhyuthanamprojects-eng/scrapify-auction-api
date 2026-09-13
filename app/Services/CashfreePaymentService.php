<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class CashfreePaymentService
{
    public function createOrder(string $orderId, float $amount, array $customer, ?string $returnUrl = null): array
    {
        if (! GeneralSettings::bool('cashfree_pg_enabled', (bool) config('services.cashfree_pg.enabled', false))) {
            throw new RuntimeException('Cashfree Payment Gateway is disabled.');
        }
        $clientId = GeneralSettings::secret('cashfree_pg_client_id', config('services.cashfree_pg.client_id'));
        $clientSecret = GeneralSettings::secret('cashfree_pg_client_secret', config('services.cashfree_pg.client_secret'));
        if (! filled($clientId) || ! filled($clientSecret)) throw new RuntimeException('Cashfree Payment Gateway credentials are not configured.');
        $environment = GeneralSettings::string('cashfree_pg_environment', (string) config('services.cashfree_pg.environment', 'test'));
        $baseUrl = $environment === 'production' ? 'https://api.cashfree.com/pg' : 'https://sandbox.cashfree.com/pg';
        $payload = [
            'order_id' => $orderId, 'order_amount' => round($amount, 2), 'order_currency' => 'INR',
            'customer_details' => [
                'customer_id' => (string) ($customer['id'] ?? $orderId),
                'customer_name' => (string) ($customer['name'] ?? 'Scrapify Customer'),
                'customer_email' => (string) ($customer['email'] ?? 'payments@scrapifyauctions.com'),
                'customer_phone' => (string) ($customer['phone'] ?? '9999999999'),
            ],
            'order_meta' => ['return_url' => $returnUrl ?: config('app.url').'/payment-return?order_id={order_id}'],
            'order_note' => 'Scrapify payment',
        ];
        $response = Http::timeout(max(5, min(120, GeneralSettings::int('cashfree_pg_timeout', (int) config('services.cashfree_pg.timeout', 30)))))
            ->acceptJson()->asJson()->withHeaders([
                'x-api-version' => GeneralSettings::string('cashfree_pg_api_version', (string) config('services.cashfree_pg.api_version', '2025-01-01')),
                'x-client-id' => $clientId, 'x-client-secret' => $clientSecret, 'x-idempotency-key' => (string) Str::uuid(),
            ])->post($baseUrl.'/orders', $payload);
        if ($response->failed()) throw new RuntimeException('Cashfree order creation failed (HTTP '.$response->status().').');
        $body = $response->json();
        return [
            'provider' => 'CASHFREE', 'environment' => $environment,
            'order_id' => $body['order_id'] ?? $orderId, 'cf_order_id' => $body['cf_order_id'] ?? null,
            'payment_session_id' => $body['payment_session_id'] ?? null, 'order_status' => $body['order_status'] ?? null,
            'order_amount' => $body['order_amount'] ?? $payload['order_amount'], 'created_at' => $body['created_at'] ?? null,
        ];
    }
}
