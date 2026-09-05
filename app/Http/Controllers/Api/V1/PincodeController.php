<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PincodeController extends Controller
{
    public function lookup(string $pincode): JsonResponse
    {
        if (! preg_match('/^\d{6}$/', $pincode)) {
            return response()->json([
                'message' => 'Invalid pincode. Must be 6 digits.',
            ], 422);
        }

        $data = Cache::remember("pincode:{$pincode}", now()->addDays(30), function () use ($pincode) {
            $response = Http::timeout(5)->get("https://api.postalpincode.in/pincode/{$pincode}");

            if ($response->failed()) {
                return null;
            }

            $result = $response->json();

            if (empty($result) || $result[0]['Status'] !== 'Success' || empty($result[0]['PostOffice'])) {
                return null;
            }

            $offices = $result[0]['PostOffice'];
            $first = $offices[0];

            return [
                'pincode' => $pincode,
                'city' => $first['District'],
                'state' => $first['State'],
                'country' => $first['Country'],
                'post_offices' => array_map(fn ($po) => [
                    'name' => $po['Name'],
                    'type' => $po['BranchType'],
                    'delivery' => $po['DeliveryStatus'],
                    'division' => $po['Division'],
                    'region' => $po['Region'],
                    'block' => $po['Block'],
                ], $offices),
            ];
        });

        if (! $data) {
            return response()->json([
                'message' => 'No results found for this pincode.',
            ], 404);
        }

        return response()->json($data);
    }
}
