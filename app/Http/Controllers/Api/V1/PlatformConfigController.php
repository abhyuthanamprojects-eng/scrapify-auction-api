<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class PlatformConfigController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'vendor_registration_fee' => (float) config('scrapify.vendor_registration_fee'),
            'currency' => 'INR',
        ]);
    }
}
