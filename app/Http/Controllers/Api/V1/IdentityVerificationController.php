<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Exceptions\VerificationProviderException;
use App\Services\IdentityVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityVerificationController extends Controller
{
    public function status(Request $request, IdentityVerificationService $service): JsonResponse
    {
        $status = $service->status($request->user());

        return response()->json([
            'success' => true,
            'data' => $status ?? ['status' => 'NOT_STARTED', 'provider' => 'DIGILOCKER'],
        ]);
    }

    public function initiate(Request $request, IdentityVerificationService $service): JsonResponse
    {
        $data = $request->validate([
            'redirect_uri' => ['required', 'url', 'max:500'],
            'subject_type' => ['sometimes', 'string', 'in:buyer,seller'],
        ]);

        $user = $request->user();
        $subjectType = $data['subject_type'] ?? $user->role;
        $subjectId = $user->vendor_id;

        try {
            $result = $service->initiate($user, $subjectType, $subjectId, $data['redirect_uri']);

            if ($result['already_verified']) {
                return response()->json(['success' => true, 'data' => $result['verification'], 'already_verified' => true]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'authorization_url' => $result['authorization_url'],
                    'expires_at' => $result['expires_at'],
                ],
            ]);
        } catch (VerificationProviderException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => ['code' => $e->errorCode],
            ], $e->httpStatus);
        }
    }

    public function callback(Request $request, IdentityVerificationService $service): JsonResponse
    {
        $data = $request->validate([
            'state' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:2000'],
            'error' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $verification = $service->handleCallback(
                $data['state'],
                $data['code'] ?? null,
                $data['error'] ?? null,
            );

            return response()->json([
                'success' => true,
                'data' => $service->present($verification),
            ]);
        } catch (VerificationProviderException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => ['code' => $e->errorCode],
            ], $e->httpStatus);
        }
    }

    public function retry(Request $request, IdentityVerificationService $service): JsonResponse
    {
        $data = $request->validate([
            'redirect_uri' => ['required', 'url', 'max:500'],
        ]);

        try {
            $result = $service->retry($request->user(), $data['redirect_uri']);

            if ($result['already_verified'] ?? false) {
                return response()->json(['success' => true, 'data' => $result['verification'], 'already_verified' => true]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'authorization_url' => $result['authorization_url'],
                    'expires_at' => $result['expires_at'],
                ],
            ]);
        } catch (VerificationProviderException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => ['code' => $e->errorCode],
            ], $e->httpStatus);
        }
    }
}
