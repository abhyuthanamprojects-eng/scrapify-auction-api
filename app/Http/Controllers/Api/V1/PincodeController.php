<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PincodeLookupService;
use Illuminate\Http\JsonResponse;

class PincodeController extends Controller
{
    public function lookup(string $pincode, PincodeLookupService $pincodeService): JsonResponse
    {
        if (! preg_match('/^\d{6}$/', $pincode)) {
            return response()->json([
                'message' => 'Invalid pincode. Must be 6 digits.',
            ], 422);
        }

        $data = $pincodeService->lookup($pincode);

        if (! $data) {
            return response()->json([
                'message' => 'No results found for this pincode.',
            ], 404);
        }

        return response()->json($data);
    }
}
