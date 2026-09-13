<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Vendor;
use App\Rules\IndianMobileNumber;
use App\Services\AuditLogger;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const PUBLIC_TOKEN_ABILITIES = ['public:web'];
    private const ADMIN_TOKEN_ABILITIES = ['admin:panel'];

    public function __construct(private readonly OtpService $otpService) {}

    /**
     * Step 1 + 2 of the mobile signup wizard: identity plus login credentials.
     * Company details and KYC arrive later via the vendors endpoints.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:20', new IndianMobileNumber(), 'unique:users,phone'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
            'role' => ['sometimes', Rule::in(['buyer', 'seller'])],
            'registration_type' => ['sometimes', Rule::in(['buyer', 'seller', 'BUYER', 'SELLER'])],
            'company_name' => ['sometimes', 'string', 'max:180'],
        ]);

        $role = strtolower($data['registration_type'] ?? $data['role'] ?? 'buyer');
        if (isset($data['role'], $data['registration_type']) && strtolower($data['role']) !== $role) {
            throw ValidationException::withMessages(['registration_type' => 'Registration role does not match the selected account role.']);
        }

        $data['email'] = strtolower(trim($data['email']));
        $data['phone'] = $this->otpService->normalizeIdentifier($data['phone']);
        if (! preg_match('/^[6-9]\d{9}$/', $data['phone'])) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid Indian mobile number.']);
        }
        if (User::where('phone', $data['phone'])->exists()) {
            throw ValidationException::withMessages(['phone' => 'This mobile number is already registered.']);
        }
        if (! $this->otpService->hasRecentVerification($data['email'], 'email', 'register')) {
            throw ValidationException::withMessages(['email' => 'Verify your email OTP before creating your account.']);
        }
        if (! $this->otpService->hasRecentVerification($data['phone'], 'sms', 'register')) {
            throw ValidationException::withMessages(['phone' => 'Verify your mobile OTP before creating your account.']);
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'password' => $data['password'],
            'role' => $role,
            'status' => 'active',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
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

        AuditLogger::write('Registered public user', 'User', $user->uuid, ['role' => $role]);
        app(\App\Services\NotificationService::class)->notifyAdmins(
            'NEW_USER_REGISTRATION',
            $role === 'seller' ? 'New seller registration' : 'New buyer registration',
            "{$user->name} submitted a {$role} account registration.",
            ['user_id' => $user->id, 'vendor_id' => $vendor->id, 'role' => $role],
            "user:{$user->id}:registration",
        );

        return response()->json([
            'user' => new UserResource($user->load('vendor')),
            'token' => $this->issueToken($user, 'public-web')->plainTextToken,
        ], 201);
    }

    /** Password login. Accepts email or phone as `identifier`. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
            'login_context' => ['sometimes', Rule::in(['buyer', 'seller', 'BUYER', 'SELLER'])],
        ]);

        $identifier = $this->normalizeLookupIdentifier($data['identifier']);
        $user = User::where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => 'These credentials do not match our records.',
            ]);
        }

        if (! $user->isPublicUser()) {
            AuditLogger::writeFor($user, 'Rejected public login for internal account', 'User', $user->uuid, [
                'auth_context' => 'public-web',
                'reason_code' => 'ADMIN_LOGIN_NOT_ALLOWED_HERE',
            ]);
            return $this->contextDenied(
                'This account must sign in through the Admin Portal.',
                'ADMIN_LOGIN_NOT_ALLOWED_HERE'
            );
        }

        // The selector is only a user-facing context hint. The persisted role
        // remains authoritative and a mismatch must never mint a token.
        if (isset($data['login_context']) && strtolower($data['login_context']) !== $user->role) {
            $expected = ucfirst($user->role);
            AuditLogger::writeFor($user, 'Rejected login for mismatched public role context', 'User', $user->uuid, [
                'auth_context' => 'public-web',
                'reason_code' => 'ROLE_CONTEXT_MISMATCH',
            ]);
            return response()->json([
                'message' => "This account is registered as a {$expected}. Please sign in using {$expected} Login.",
                'error' => ['code' => 'ROLE_CONTEXT_MISMATCH', 'account_role' => $user->role],
            ], 403);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages(['identifier' => 'This account is not active.']);
        }

        $user->update(['last_login_at' => now()]);
        AuditLogger::writeFor($user, 'Authenticated through public web context', 'User', $user->uuid, [
            'auth_context' => 'public-web',
        ]);

        // Single session: revoke all previous tokens
        $user->tokens()->delete();

        return response()->json([
            'user' => new UserResource($user->load(['vendor', 'organization'])),
            'token' => $this->issueToken($user, 'public-web')->plainTextToken,
        ]);
    }

    /**
     * Admin-only password login. This is a separate endpoint by design; the
     * public login endpoint cannot mint an internal/admin session.
     */
    public function adminLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $identifier = $this->normalizeLookupIdentifier($data['identifier']);
        $user = User::where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => 'These credentials do not match an authorized admin account.',
            ]);
        }

        if (! $user->isAdmin()) {
            AuditLogger::writeFor($user, 'Rejected admin login for public account', 'User', $user->uuid, [
                'auth_context' => 'admin-panel',
                'reason_code' => 'ADMIN_ROLE_REQUIRED',
            ]);
            return $this->contextDenied(
                'Only authorized internal staff may sign in to the Admin Portal.',
                'ADMIN_ROLE_REQUIRED'
            );
        }

        if ($user->status !== 'active') {
            return $this->contextDenied(
                'This admin account is not active.',
                'ADMIN_ACCOUNT_INACTIVE'
            );
        }

        $user->update(['last_login_at' => now()]);
        AuditLogger::writeFor($user, 'Authenticated through admin panel context', 'User', $user->uuid, [
            'auth_context' => 'admin-panel',
        ]);
        $user->tokens()->delete();

        return response()->json([
            'user' => new UserResource($user->load(['vendor', 'organization'])),
            'token' => $this->issueToken($user, 'admin-panel')->plainTextToken,
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
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', new IndianMobileNumber()],
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

        $phone = filled($data['phone'] ?? null) ? $this->otpService->normalizeIdentifier($data['phone']) : null;
        if ($phone !== null && ! preg_match('/^[6-9]\d{9}$/', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid Indian mobile number.']);
        }

        $user = User::where('email', strtolower($email))->first();

        if ($user) {
            if (! $user->isPublicUser()) {
                return $this->contextDenied(
                    'This account must sign in through the Admin Portal.',
                    'ADMIN_LOGIN_NOT_ALLOWED_HERE'
                );
            }

            if ($phone !== null && $phone !== $user->phone) {
                if (User::where('phone', $phone)->where('id', '<>', $user->id)->exists()) {
                    throw ValidationException::withMessages(['phone' => 'This mobile number is already registered.']);
                }
                if (! $this->otpService->hasRecentVerification($phone, 'sms', 'register')) {
                    throw ValidationException::withMessages(['phone' => 'Verify your mobile OTP before changing this number.']);
                }
            }

            $user->update([
                'email_verified_at' => $user->email_verified_at ?? now(),
                'phone' => $phone ?? $user->phone,
                'phone_verified_at' => $phone !== null && $phone !== $user->phone ? now() : $user->phone_verified_at,
                'last_login_at' => now(),
            ]);
            if ($phone !== null && $user->vendor) {
                $user->vendor->update(['phone' => $phone]);
            }
        } else {
            if (! $phone) {
                throw ValidationException::withMessages(['phone' => 'Verify your mobile number before completing Google registration.']);
            }
            if (! $this->otpService->hasRecentVerification($phone, 'sms', 'register')) {
                throw ValidationException::withMessages(['phone' => 'Verify your mobile OTP before completing Google registration.']);
            }

            $user = User::create([
                'name' => $name,
                'email' => strtolower($email),
                'phone' => $phone,
                'password' => Hash::make(bin2hex(random_bytes(16))),
                'role' => $data['role'] ?? 'buyer',
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'google_id' => $payload['sub'],
            ]);

            $vendor = Vendor::create([
                'user_id' => $user->id,
                'company_name' => $name,
                'contact_name' => $name,
                'email' => $email,
                'phone' => $phone,
                'status' => 'pending',
                'registration_step' => 2,
            ]);

            $user->update(['vendor_id' => $vendor->id]);

            AuditLogger::write('Registered public user through Google', 'User', $user->uuid, ['role' => $user->role]);
        }

        // Single session: revoke all previous tokens
        $user->tokens()->delete();

        return response()->json([
            'user' => new UserResource($user->load(['vendor', 'organization'])),
            'token' => $this->issueToken($user, 'public-web')->plainTextToken,
            'is_new' => $user->wasRecentlyCreated,
        ]);
    }

    private function verifyGoogleIdToken(string $idToken): ?array
    {
        $projectId = \App\Services\GeneralSettings::string('firebase_project_id', (string) config('services.google.firebase_project_id'));

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
            $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
            if (($header['alg'] ?? null) !== 'RS256' || ! filled($payload['sub'] ?? null)) {
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

    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'purpose' => ['required', Rule::in(['login', 'register', 'verify'])],
        ]);

        $identifier = $this->normalizeOtpIdentifier($data['identifier']);
        $result = $this->otpService->request($identifier, $data['purpose']);
        if (! $result['success']) {
            return $this->otpFailure($result);
        }

        return response()->json([
            'message' => 'OTP sent.',
            'channel' => $this->otpService->channel($identifier),
            'otp_length' => $result['otp_length'] ?? $this->otpService->otpLength($identifier),
            'destination' => $this->maskDestination($identifier),
            'expires_at' => $result['expires_at'],
            'resend_after' => $result['resend_after'],
        ]);
    }

    public function resendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'purpose' => ['required', Rule::in(['login', 'register', 'verify'])],
        ]);

        $identifier = $this->normalizeOtpIdentifier($data['identifier']);
        $result = $this->otpService->resend($identifier, $data['purpose']);
        if (! $result['success']) {
            return $this->otpFailure($result);
        }

        return response()->json([
            'message' => 'OTP resent.',
            'channel' => $this->otpService->channel($identifier),
            'otp_length' => $result['otp_length'] ?? $this->otpService->otpLength($identifier),
            'destination' => $this->maskDestination($identifier),
            'expires_at' => $result['expires_at'],
            'resend_after' => $result['resend_after'],
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string'],
            'code' => ['required', 'digits:4'],
            'purpose' => ['required', Rule::in(['login', 'register', 'verify'])],
        ]);

        $identifier = $this->normalizeOtpIdentifier($data['identifier']);
        validator(['code' => $data['code']], [
            'code' => ['digits:4'],
        ])->validate();
        if (! $this->otpService->verify($identifier, $data['purpose'], $data['code'])) {
            throw ValidationException::withMessages(['code' => 'This OTP is invalid or has expired.']);
        }

        $user = User::where('email', $identifier)
            ->orWhere('phone', $identifier)
            ->first();

        if ($data['purpose'] === 'register') {
            return response()->json(['verified' => true, 'user' => null, 'token' => null]);
        }

        if (! $user) {
            throw ValidationException::withMessages(['identifier' => 'No account exists for this identifier.']);
        }

        if (! $user->isPublicUser()) {
            return $this->contextDenied(
                'This account must sign in through the Admin Portal.',
                'ADMIN_LOGIN_NOT_ALLOWED_HERE'
            );
        }

        $user->forceFill([
            'phone_verified_at' => $this->otpService->channel($identifier) === 'sms' ? now() : $user->phone_verified_at,
            'email_verified_at' => $this->otpService->channel($identifier) === 'email' ? now() : $user->email_verified_at,
            'last_login_at' => now(),
        ])->save();

        // Single session: revoke all previous tokens
        $user->tokens()->delete();

        return response()->json([
            'verified' => true,
            'user' => new UserResource($user->load(['vendor', 'organization'])),
            'token' => $this->issueToken($user, 'public-web')->plainTextToken,
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

    private function issueToken(User $user, string $name): \Laravel\Sanctum\NewAccessToken
    {
        $abilities = $name === 'admin-panel'
            ? self::ADMIN_TOKEN_ABILITIES
            : self::PUBLIC_TOKEN_ABILITIES;

        return $user->createToken($name, $abilities);
    }

    private function contextDenied(string $message, string $code): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error' => ['code' => $code],
        ], 403);
    }

    private function normalizeLookupIdentifier(string $identifier): string
    {
        return $this->otpService->normalizeIdentifier($identifier);
    }

    private function normalizeOtpIdentifier(string $identifier): string
    {
        $normalized = $this->otpService->normalizeIdentifier($identifier);
        if ($this->otpService->channel($normalized) === 'email') {
            validator(['identifier' => $normalized], ['identifier' => ['email']])->validate();
        } elseif (! preg_match('/^[6-9]\d{9}$/', $normalized)) {
            throw ValidationException::withMessages(['identifier' => 'Enter a valid email address or Indian mobile number.']);
        }

        return $normalized;
    }

    private function maskDestination(string $identifier): string
    {
        if ($this->otpService->channel($identifier) === 'email') {
            [$name, $domain] = explode('@', $identifier, 2);
            return substr($name, 0, 2) . str_repeat('*', max(2, strlen($name) - 2)) . '@' . $domain;
        }

        return str_repeat('*', max(0, strlen($identifier) - 4)) . substr($identifier, -4);
    }

    private function otpFailure(array $result): JsonResponse
    {
        $status = in_array($result['code'] ?? null, ['OTP_PROVIDER_UNAVAILABLE', 'EMAIL_PROVIDER_UNAVAILABLE'], true) ? 503 : 422;

        return response()->json([
            'message' => $result['message'],
            'error' => ['code' => $result['code'] ?? 'OTP_REQUEST_FAILED'],
        ], $status);
    }
}
