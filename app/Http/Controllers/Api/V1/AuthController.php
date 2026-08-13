<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Otp;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Step 1 + 2 of the mobile signup wizard: identity plus login credentials.
     * Company details and KYC arrive later via the vendors endpoints.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['sometimes', Rule::in(['buyer', 'seller'])],
            'company_name' => ['sometimes', 'string', 'max:180'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => $data['password'],
            'role' => $data['role'] ?? 'buyer',
        ]);

        // Buyers and sellers both trade as a vendor company on this platform.
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'company_name' => $data['company_name'] ?? $data['name'],
            'contact_name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'status' => 'pending',
            'registration_step' => 2,
        ]);

        $user->update(['vendor_id' => $vendor->id]);

        AuditLogger::write("Registered user {$user->email}", 'User', $user->uuid);

        return response()->json([
            'user' => new UserResource($user->load('vendor')),
            'token' => $user->createToken('api')->plainTextToken,
        ], 201);
    }

    /** Password login. Accepts email or phone as `identifier`. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['identifier'])
            ->orWhere('phone', $data['identifier'])
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => 'These credentials do not match our records.',
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages(['identifier' => 'This account is not active.']);
        }

        $user->update(['last_login_at' => now()]);

        return response()->json([
            'user' => new UserResource($user->load(['vendor', 'organization'])),
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }

    /**
     * Request an OTP. In local dev the code is returned in the response so the
     * mobile and web clients can be exercised without an SMS provider.
     */
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'purpose' => ['sometimes', Rule::in(['login', 'register', 'verify'])],
        ]);

        $otp = Otp::create([
            'identifier' => $data['identifier'],
            'channel' => filter_var($data['identifier'], FILTER_VALIDATE_EMAIL) ? 'email' : 'sms',
            'purpose' => $data['purpose'] ?? 'login',
            'code' => (string) random_int(100000, 999999),
            'expires_at' => now()->addMinutes(10),
        ]);

        return response()->json([
            'message' => 'OTP sent.',
            'expires_at' => $otp->expires_at->toIso8601String(),
            'debug_code' => app()->environment('local') ? $otp->code : null,
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $otp = Otp::where('identifier', $data['identifier'])
            ->where('code', $data['code'])
            ->latest('id')
            ->first();

        if (! $otp || ! $otp->isUsable()) {
            throw ValidationException::withMessages(['code' => 'This OTP is invalid or has expired.']);
        }

        $otp->update(['consumed_at' => now()]);

        $user = User::where('email', $data['identifier'])
            ->orWhere('phone', $data['identifier'])
            ->first();

        if (! $user) {
            // Verification during signup, before the account exists.
            return response()->json(['verified' => true, 'user' => null, 'token' => null]);
        }

        $user->forceFill([
            'phone_verified_at' => $otp->channel === 'sms' ? now() : $user->phone_verified_at,
            'email_verified_at' => $otp->channel === 'email' ? now() : $user->email_verified_at,
            'last_login_at' => now(),
        ])->save();

        return response()->json([
            'verified' => true,
            'user' => new UserResource($user->load(['vendor', 'organization'])),
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->load(['vendor.materials', 'organization', 'wallet'])),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
