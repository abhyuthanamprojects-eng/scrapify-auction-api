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
     * Google Sign-In: verify a Google ID token, find or create the user,
     * and return an API token.
     */
    public function googleSignIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id_token' => ['required', 'string'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'role' => ['sometimes', Rule::in(['buyer', 'seller'])],
        ]);

        $payload = $this->verifyGoogleIdToken($data['id_token']);

        if (! $payload) {
            throw ValidationException::withMessages([
                'id_token' => 'Invalid or expired Google token.',
            ]);
        }

        $email = $payload['email'] ?? null;
        $name = $payload['name'] ?? ($payload['given_name'] ?? 'User');

        if (! $email) {
            throw ValidationException::withMessages([
                'id_token' => 'Google account does not have an email address.',
            ]);
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'email_verified_at' => $user->email_verified_at ?? now(),
                'last_login_at' => now(),
            ]);
        } else {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make(bin2hex(random_bytes(16))),
                'role' => $data['role'] ?? 'buyer',
                'email_verified_at' => now(),
                'google_id' => $payload['sub'],
            ]);

            $vendor = Vendor::create([
                'user_id' => $user->id,
                'company_name' => $name,
                'contact_name' => $name,
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'status' => 'pending',
                'registration_step' => 2,
            ]);

            $user->update(['vendor_id' => $vendor->id]);

            AuditLogger::write("Google sign-up: {$email}", 'User', $user->uuid);
        }

        return response()->json([
            'user' => new UserResource($user->load(['vendor', 'organization'])),
            'token' => $user->createToken('api')->plainTextToken,
            'is_new' => ! $user->wasRecentlyCreated ? false : true,
        ]);
    }

    private function verifyGoogleIdToken(string $idToken): ?array
    {
        $projectId = config('services.google.firebase_project_id');

        try {
            // Decode the JWT payload without signature verification first
            $parts = explode('.', $idToken);
            if (count($parts) !== 3) {
                return null;
            }

            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if (! $payload) {
                return null;
            }

            // Verify issuer matches Firebase project
            $expectedIssuer = "https://securetoken.google.com/{$projectId}";
            if (($payload['iss'] ?? '') !== $expectedIssuer) {
                return null;
            }

            // Verify audience matches Firebase project
            if (($payload['aud'] ?? '') !== $projectId) {
                return null;
            }

            // Verify token is not expired
            if (($payload['exp'] ?? 0) < time()) {
                return null;
            }

            // Verify signature using Google's public keys
            $keysResponse = \Illuminate\Support\Facades\Http::get(
                'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com'
            );

            if ($keysResponse->failed()) {
                return null;
            }

            $keys = $keysResponse->json();
            $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
            $kid = $header['kid'] ?? '';

            if (! isset($keys[$kid])) {
                return null;
            }

            $certificate = openssl_pkey_get_public($keys[$kid]);
            if (! $certificate) {
                return null;
            }

            $signatureValid = openssl_verify(
                $parts[0] . '.' . $parts[1],
                base64_decode(strtr($parts[2], '-_', '+/')),
                $certificate,
                OPENSSL_ALGO_SHA256
            );

            if ($signatureValid !== 1) {
                return null;
            }

            $email = $payload['email'] ?? null;
            $emailVerified = $payload['email_verified'] ?? false;

            if (! $email || ! $emailVerified) {
                return null;
            }

            return [
                'sub' => $payload['sub'] ?? '',
                'email' => $email,
                'email_verified' => $emailVerified,
                'name' => $payload['name'] ?? ($payload['given_name'] ?? 'User'),
                'given_name' => $payload['given_name'] ?? '',
                'picture' => $payload['picture'] ?? '',
            ];
        } catch (\Throwable) {
            return null;
        }
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
