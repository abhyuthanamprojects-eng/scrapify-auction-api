<?php

namespace App\Services;

use App\Contracts\IdentityVerificationProviderInterface;
use App\Exceptions\VerificationProviderException;
use App\Models\IdentityVerification;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

final class IdentityVerificationService
{
    public function __construct(private readonly IdentityVerificationProviderInterface $provider) {}

    public function status(User $user): ?array
    {
        $verification = $this->latestForUser($user);
        if (! $verification) {
            return null;
        }

        return $this->present($verification);
    }

    public function initiate(User $user, string $subjectType, ?int $subjectId, string $redirectUri): array
    {
        $existing = $this->latestForUser($user);
        if ($existing && $existing->status === 'VERIFIED') {
            return ['already_verified' => true, 'verification' => $this->present($existing)];
        }

        $this->expirePending($user);

        $state = bin2hex(random_bytes(32));
        $stateHash = hash('sha256', $state);
        $codeVerifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $authorizationUrl = $this->provider->buildAuthorizationUrl($state, $codeVerifier, $redirectUri);

        $verification = IdentityVerification::create([
            'user_id' => $user->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'verification_type' => 'AADHAAR',
            'provider' => strtoupper($this->provider->key()),
            'status' => 'INITIATED',
            'state_hash' => $stateHash,
            'code_verifier_encrypted' => $codeVerifier,
            'requested_scopes' => 'openid',
            'redirect_uri' => $redirectUri,
            'initiated_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        AuditLogger::write('DIGILOCKER_VERIFICATION_INITIATED', 'identity_verification', (string) $verification->id, [
            'user_id' => $user->id,
            'subject_type' => $subjectType,
        ]);

        return [
            'already_verified' => false,
            'authorization_url' => $authorizationUrl,
            'state' => $state,
            'expires_at' => $verification->expires_at->toIso8601String(),
            'verification_id' => $verification->id,
        ];
    }

    public function handleCallback(string $state, ?string $code, ?string $error): IdentityVerification
    {
        $stateHash = hash('sha256', $state);
        $verification = IdentityVerification::where('state_hash', $stateHash)
            ->whereIn('status', ['INITIATED'])
            ->first();

        if (! $verification) {
            throw new VerificationProviderException('DIGILOCKER_STATE_MISMATCH', 'Invalid or expired verification state.', 422);
        }

        if ($verification->expires_at && $verification->expires_at->isPast()) {
            $verification->update(['status' => 'EXPIRED', 'failure_code' => 'DIGILOCKER_SESSION_EXPIRED']);
            AuditLogger::write('DIGILOCKER_VERIFICATION_EXPIRED', 'identity_verification', (string) $verification->id);
            throw new VerificationProviderException('DIGILOCKER_SESSION_EXPIRED', 'The verification session has expired. Please try again.', 422);
        }

        $verification->update(['callback_received_at' => now()]);
        AuditLogger::write('DIGILOCKER_CALLBACK_RECEIVED', 'identity_verification', (string) $verification->id);

        if ($error || ! $code) {
            $failureCode = match ($error) {
                'access_denied' => 'DIGILOCKER_ACCESS_DENIED',
                'user_cancelled', 'consent_denied' => 'DIGILOCKER_AUTH_CANCELLED',
                default => 'DIGILOCKER_CALLBACK_INVALID',
            };
            $verification->update([
                'status' => $error === 'access_denied' || $error === 'user_cancelled' || $error === 'consent_denied' ? 'CANCELLED' : 'FAILED',
                'failure_code' => $failureCode,
            ]);
            AuditLogger::write('DIGILOCKER_VERIFICATION_CANCELLED', 'identity_verification', (string) $verification->id, [
                'error' => $error,
                'failure_code' => $failureCode,
            ]);
            throw new VerificationProviderException($failureCode, 'DigiLocker authorization was not completed.', 422);
        }

        try {
            $tokenData = $this->provider->exchangeCode(
                $code,
                $verification->code_verifier_encrypted,
                $verification->redirect_uri,
            );

            $accessToken = $tokenData['access_token'];
            $verification->update([
                'provider_reference' => $tokenData['digilocker_id'] ?? $tokenData['reference_id'] ?? null,
                'consent_reference' => $tokenData['consent_id'] ?? null,
            ]);

            AuditLogger::write('DIGILOCKER_CONSENT_COMPLETED', 'identity_verification', (string) $verification->id);

            $identity = $this->provider->fetchIdentity($accessToken);

            $verification->update([
                'status' => 'VERIFIED',
                'identity_name' => $identity['name'] ?? null,
                'dob_encrypted' => $identity['dob'] ?? null,
                'gender' => $identity['gender'] ?? null,
                'aadhaar_last4' => $identity['aadhaar_last4'] ?? null,
                'digilocker_id' => $identity['digilocker_id'] ?? $tokenData['digilocker_id'] ?? null,
                'verified_at' => now(),
                'code_verifier_encrypted' => null,
                'failure_code' => null,
            ]);

            AuditLogger::write('DIGILOCKER_IDENTITY_VERIFIED', 'identity_verification', (string) $verification->id, [
                'user_id' => $verification->user_id,
                'has_name' => ! empty($identity['name']),
                'has_aadhaar_last4' => ! empty($identity['aadhaar_last4']),
            ]);

            return $verification->fresh();
        } catch (VerificationProviderException $e) {
            $verification->update([
                'status' => 'FAILED',
                'failure_code' => $e->errorCode,
                'code_verifier_encrypted' => null,
            ]);
            AuditLogger::write('DIGILOCKER_VERIFICATION_FAILED', 'identity_verification', (string) $verification->id, [
                'error_code' => $e->errorCode,
            ]);
            throw $e;
        } catch (Throwable $e) {
            $verification->update([
                'status' => 'FAILED',
                'failure_code' => 'DIGILOCKER_PROVIDER_UNAVAILABLE',
                'code_verifier_encrypted' => null,
            ]);
            AuditLogger::write('DIGILOCKER_VERIFICATION_FAILED', 'identity_verification', (string) $verification->id, [
                'error_code' => 'DIGILOCKER_PROVIDER_UNAVAILABLE',
            ]);
            throw new VerificationProviderException('DIGILOCKER_PROVIDER_UNAVAILABLE', 'DigiLocker verification failed. Please try again.', 502);
        }
    }

    public function retry(User $user, string $redirectUri): array
    {
        $existing = $this->latestForUser($user);
        if ($existing && $existing->status === 'VERIFIED') {
            return ['already_verified' => true, 'verification' => $this->present($existing)];
        }

        $subjectType = $existing?->subject_type ?? $user->role;
        $subjectId = $existing?->subject_id;

        return $this->initiate($user, $subjectType, $subjectId, $redirectUri);
    }

    public function present(IdentityVerification $v): array
    {
        return [
            'id' => $v->id,
            'provider' => $v->provider,
            'status' => $v->status,
            'verification_type' => $v->verification_type,
            'identity_name' => $v->identity_name,
            'aadhaar_masked' => $v->aadhaar_last4 ? 'XXXX XXXX ' . $v->aadhaar_last4 : null,
            'gender' => $v->gender,
            'digilocker_id' => $v->digilocker_id,
            'verified_at' => $v->verified_at?->toIso8601String(),
            'initiated_at' => $v->initiated_at?->toIso8601String(),
            'failure_code' => $v->failure_code,
        ];
    }

    private function latestForUser(User $user): ?IdentityVerification
    {
        return IdentityVerification::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->first();
    }

    private function expirePending(User $user): void
    {
        IdentityVerification::where('user_id', $user->id)
            ->where('status', 'INITIATED')
            ->update(['status' => 'EXPIRED', 'failure_code' => 'DIGILOCKER_SESSION_EXPIRED']);
    }
}
