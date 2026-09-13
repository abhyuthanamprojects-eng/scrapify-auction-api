<?php

namespace App\Services;

use App\Exceptions\PincodeProviderException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;

class PincodeLookupService
{
    public function lookup(string $pincode): ?array
    {
        if (! preg_match('/^\d{6}$/', $pincode)) {
            return null;
        }

        try {
            return Cache::remember("pincode:{$pincode}", now()->addDays(30), function () use ($pincode) {
                $response = Http::connectTimeout(3)
                    ->timeout(5)
                    ->acceptJson()
                    ->get("https://api.postalpincode.in/pincode/{$pincode}");

                if ($response->status() === 429 || $response->serverError()) {
                    throw new PincodeProviderException(
                        $response->status() === 429
                            ? 'PINCODE_PROVIDER_RATE_LIMITED'
                            : 'PINCODE_PROVIDER_UNAVAILABLE',
                        'The pincode service is temporarily unavailable.',
                        503,
                        60,
                    );
                }

                if ($response->failed()) {
                    return null;
                }

                $result = $response->json();
                if (empty($result) || ($result[0]['Status'] ?? null) !== 'Success' || empty($result[0]['PostOffice'])) {
                    return null;
                }

                $offices = $result[0]['PostOffice'];
                $first = $offices[0];

                return [
                    'pincode' => $pincode,
                    'city' => $first['District'] ?? '',
                    'state' => $first['State'] ?? '',
                    'country' => $first['Country'] ?? 'India',
                    'post_offices' => array_map(fn ($po) => [
                        'name' => $po['Name'] ?? '',
                        'type' => $po['BranchType'] ?? '',
                        'delivery' => $po['DeliveryStatus'] ?? '',
                        'division' => $po['Division'] ?? '',
                        'region' => $po['Region'] ?? '',
                        'block' => $po['Block'] ?? '',
                    ], $offices),
                ];
            });
        } catch (PincodeProviderException $exception) {
            Log::warning('Pincode provider returned an unavailable response.', [
                'error_code' => $exception->errorCode,
                'pincode' => $pincode,
                'status' => $exception->httpStatus,
            ]);

            throw $exception;
        } catch (ConnectionException $exception) {
            Log::warning('Pincode provider connection failed.', [
                'error_code' => 'PINCODE_PROVIDER_UNAVAILABLE',
                'pincode' => $pincode,
            ]);

            throw new PincodeProviderException(
                'PINCODE_PROVIDER_UNAVAILABLE',
                'The pincode service is temporarily unavailable.',
                503,
                30,
                $exception,
            );
        }
    }

    public function matches(string $pincode, ?string $city, ?string $state): bool
    {
        try {
            $result = $this->lookup($pincode);
        } catch (PincodeProviderException) {
            return false;
        }

        return $result !== null
            && $this->same($result['city'] ?? null, $city)
            && $this->same($result['state'] ?? null, $state);
    }

    private function same(?string $expected, ?string $actual): bool
    {
        return filled($expected) && filled($actual)
            && mb_strtolower(trim($expected)) === mb_strtolower(trim($actual));
    }
}
