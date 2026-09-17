<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class RazorpayPaymentService
{
    public function createOrder(int $amountPaise, string $currency = 'INR', ?string $receipt = null, array $notes = []): array
    {
        $this->ensureReady();

        if ($amountPaise < 100) {
            throw new RuntimeException('Minimum amount is 100 paise (₹1).');
        }

        $keyId = $this->keyId();
        $keySecret = $this->keySecret();

        $response = Http::timeout($this->timeout())
            ->withBasicAuth($keyId, $keySecret)
            ->acceptJson()
            ->asJson()
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => $amountPaise,
                'currency' => $currency,
                'receipt' => $receipt ?? 'rcpt_' . Str::random(16),
                'notes' => $notes ?: (object) [],
            ]);

        if ($response->failed()) {
            $error = $response->json('error.description') ?? 'Razorpay order creation failed';
            throw new RuntimeException($error . ' (HTTP ' . $response->status() . ')');
        }

        $body = $response->json();

        return [
            'razorpay_order_id' => $body['id'],
            'amount' => $body['amount'],
            'currency' => $body['currency'],
            'status' => $body['status'],
            'key_id' => $keyId,
        ];
    }

    public function verifySignature(string $orderId, string $paymentId, string $signature): bool
    {
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $this->keySecret());

        return hash_equals($expected, $signature);
    }

    public function fetchPayment(string $paymentId): array
    {
        $this->ensureReady();

        $response = Http::timeout($this->timeout())
            ->withBasicAuth($this->keyId(), $this->keySecret())
            ->acceptJson()
            ->get("https://api.razorpay.com/v1/payments/{$paymentId}");

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch Razorpay payment details.');
        }

        return $response->json();
    }

    public function isEnabled(): bool
    {
        return GeneralSettings::bool('razorpay_enabled', (bool) config('services.razorpay.enabled', false));
    }

    private function ensureReady(): void
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Razorpay Payment Gateway is disabled.');
        }

        if (! filled($this->keyId()) || ! filled($this->keySecret())) {
            throw new RuntimeException('Razorpay credentials are not configured.');
        }
    }

    private function keyId(): ?string
    {
        return GeneralSettings::secret('razorpay_key_id', config('services.razorpay.key_id'));
    }

    private function keySecret(): ?string
    {
        return GeneralSettings::secret('razorpay_key_secret', config('services.razorpay.key_secret'));
    }

    private function timeout(): int
    {
        return max(5, min(120, GeneralSettings::int('razorpay_timeout', (int) config('services.razorpay.timeout', 30))));
    }
}
