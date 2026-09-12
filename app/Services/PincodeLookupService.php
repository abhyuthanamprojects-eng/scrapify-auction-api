<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PincodeLookupService
{
    public function lookup(string $pincode): ?array
    {
        if (! preg_match('/^\d{6}$/', $pincode)) {
            return null;
        }

        return Cache::remember("pincode:{$pincode}", now()->addDays(30), function () use ($pincode) {
            $response = Http::timeout(5)->get("https://api.postalpincode.in/pincode/{$pincode}");

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
    }

    public function matches(string $pincode, ?string $city, ?string $state): bool
    {
        $result = $this->lookup($pincode);

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
