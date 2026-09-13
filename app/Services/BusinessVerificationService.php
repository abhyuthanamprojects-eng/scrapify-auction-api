<?php

namespace App\Services;

use App\Exceptions\VerificationProviderException;
use App\Models\BusinessVerification;
use App\Models\GeneralSetting;
use App\Models\User;
use App\Models\VerificationProviderRequest;
use App\Services\Verification\NormalizedVerificationResult;
use App\Services\Verification\VerificationProviderResolver;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Throwable;

final class BusinessVerificationService
{
    public function __construct(private readonly VerificationProviderResolver $resolver) {}

    public function forUser(User $user): BusinessVerification
    {
        $vendor = $user->vendor;
        return BusinessVerification::firstOrCreate(
            ['user_id' => $user->id],
            ['vendor_id' => $vendor?->id, 'role_type' => $user->role, 'overall_kyb_status' => 'NOT_STARTED']
        );
    }

    public function present(BusinessVerification $verification): array
    {
        $latest = $verification->relationLoaded('providerRequests')
            ? $verification->providerRequests->sortByDesc('created_at')
            : $verification->providerRequests()->latest()->get();
        $latestGstin = $latest->firstWhere('verification_type', 'GSTIN');
        $latestBank = $latest->firstWhere('verification_type', 'BANK');

        return [
            'id' => $verification->id,
            'role_type' => $verification->role_type,
            'gstin' => $verification->gstin,
            'gstin_status' => $verification->gstin_status,
            'gstin_provider' => $verification->gstin_provider ?: $latestGstin?->provider,
            'gstin_reference_id' => $verification->gstin_reference_id,
            'gstin_verified_at' => $verification->gstin_verified_at?->toIso8601String(),
            'pan' => $verification->pan_masked,
            'pan_status' => $verification->pan_status,
            'pan_provider' => $verification->pan_provider,
            'pan_reference_id' => $verification->pan_reference_id,
            'pan_verified_at' => $verification->pan_verified_at?->toIso8601String(),
            'legal_business_name' => $verification->legal_business_name,
            'trade_business_name' => $verification->trade_business_name,
            'constitution_of_business' => $verification->constitution_of_business,
            'taxpayer_type' => $verification->taxpayer_type,
            'gst_registration_status' => $verification->gst_registration_status,
            'gst_registration_date' => $verification->gst_registration_date?->toDateString(),
            'gst_registered_address' => $verification->gst_registered_address,
            'business_activities' => $verification->business_activities,
            'bank_account_masked' => $verification->bank_account_masked,
            'ifsc' => $verification->ifsc,
            'bank_verification_status' => $verification->bank_verification_status,
            'bank_provider' => $verification->bank_provider ?: $latestBank?->provider,
            'kyc_provider' => $verification->kyc_provider ?: $latestBank?->provider,
            'bank_reference_id' => $verification->bank_reference_id,
            'bank_name' => $verification->bank_name,
            'bank_branch' => $verification->bank_branch,
            'bank_city' => $verification->bank_city,
            'bank_account_holder_name' => $verification->bank_account_holder_name,
            'bank_name_match_score' => $verification->bank_name_match_score,
            'bank_name_match_result' => $verification->bank_name_match_result,
            'business_bank_match_status' => $verification->business_bank_match_status,
            'overall_kyb_status' => $verification->overall_kyb_status,
            'last_error_code' => $verification->last_error_code,
            'review_reason' => $verification->review_reason,
            'rejection_reason' => $verification->rejection_reason,
            'verified_at' => $verification->verified_at?->toIso8601String(),
            'expires_at' => $verification->expires_at?->toIso8601String(),
        ];
    }

    public function verifyGstin(User $user, string $gstin, ?string $businessName = null): BusinessVerification
    {
        $gstin = strtoupper(preg_replace('/\s+/', '', trim($gstin)));
        if (! preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gstin)) {
            throw ValidationException::withMessages(['gstin' => 'Enter a valid 15-character GSTIN.']);
        }

        $verification = $this->forUser($user);
        $provider = $this->resolver->for('GSTIN');
        $providerKey = strtoupper($provider->key());
        if ($verification->gstin === $gstin
            && $verification->gstin_status === 'GSTIN_VERIFIED'
            && in_array(strtoupper((string) $verification->gstin_provider), ['', $providerKey], true)
            && $verification->gstin_verified_at?->addDays(GeneralSettings::int('kyb_gst_validity_days', 180))?->isFuture()) {
            return $verification;
        }

        if (! GeneralSettings::bool('kyb_allow_multiple_gstin_users', false)
            && BusinessVerification::where('gstin', $gstin)->where('user_id', '!=', $user->id)
                ->whereIn('gstin_status', ['GSTIN_VERIFIED', 'VERIFIED', 'REVIEW_REQUIRED'])->exists()) {
            $verification->update(['gstin' => $gstin, 'gstin_status' => 'GSTIN_ALREADY_REGISTERED', 'overall_kyb_status' => 'REVIEW_REQUIRED', 'last_error_code' => 'GSTIN_ALREADY_REGISTERED']);
            AuditLogger::write('GSTIN_ALREADY_REGISTERED', 'business_verification', (string) $verification->id, ['user_id' => $user->id]);
            throw ValidationException::withMessages(['gstin' => 'This GSTIN is already associated with another account and requires Admin review.']);
        }

        $hash = hash('sha256', 'GSTIN|'.$providerKey.'|'.$gstin.'|'.($businessName ?? ''));
        $request = $this->providerRequest($user, $verification, 'GSTIN', $providerKey, $hash);
        if ($request->status === 'completed' && $request->normalized_response) {
            return $this->applyGstin($verification, $this->storedResult($providerKey, 'GSTIN', $request->normalized_response));
        }

        $started = microtime(true);
        $request->update(['status' => 'processing', 'started_at' => now(), 'request_reference' => (string) Str::uuid()]);
        try {
            $result = $provider->verifyGstin($gstin, $businessName ?: $user->vendor?->company_name);
            $this->completeRequest($request, $result, $started);
            return $this->applyGstin($verification, $result);
        } catch (Throwable $e) {
            throw $this->recordProviderFailure($request, $verification, 'GSTIN', $started, $e);
        }
    }

    public function verifyBank(User $user, string $account, string $ifsc, ?string $name = null, ?string $phone = null): BusinessVerification
    {
        $account = trim($account); $ifsc = strtoupper(trim($ifsc)); $name = $name ? trim($name) : null;
        if (! preg_match('/^[A-Za-z0-9]{6,40}$/', $account)) throw ValidationException::withMessages(['bank_account' => 'Bank account must be 6 to 40 letters or digits.']);
        if (! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) throw ValidationException::withMessages(['ifsc' => 'Enter a valid 11-character IFSC.']);

        $verification = $this->forUser($user);
        if (VerificationProviderRequest::where('user_id', $user->id)->where('verification_type', 'BANK')->where('created_at', '>=', now()->subHour())->count() >= GeneralSettings::int('kyb_max_provider_attempts_per_hour', 5)) throw ValidationException::withMessages(['provider' => 'Verification retry limit reached. Please try again later.']);

        $provider = $this->resolver->for('BANK');
        $providerKey = strtoupper($provider->key());
        $hash = hash('sha256', 'BANK|'.$providerKey.'|'.$account.'|'.$ifsc.'|'.($name ?? '').'|'.($phone ?? ''));
        $request = $this->providerRequest($user, $verification, 'BANK', $providerKey, $hash);
        if ($request->status === 'completed' && $request->normalized_response) return $this->applyBank($verification, $this->storedResult($providerKey, 'BANK', $request->normalized_response), $account, $ifsc);

        $started = microtime(true);
        $request->update(['status' => 'processing', 'started_at' => now(), 'request_reference' => (string) Str::uuid()]);
        try {
            $result = $provider->verifyBankAccount($account, $ifsc, $name, $phone);
            $this->completeRequest($request, $result, $started);
            return $this->applyBank($verification, $result, $account, $ifsc);
        } catch (Throwable $e) {
            throw $this->recordProviderFailure($request, $verification, 'BANK', $started, $e);
        }
    }

    public function verifyPan(User $user, string $pan, ?string $name = null, ?string $dateOfBirth = null): BusinessVerification
    {
        $pan = strtoupper(trim($pan));
        if (! preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
            throw ValidationException::withMessages(['pan' => 'Enter a valid 10-character PAN.']);
        }

        $verification = $this->forUser($user);
        $provider = $this->resolver->for('PAN');
        $providerKey = strtoupper($provider->key());
        $hash = hash('sha256', 'PAN|'.$providerKey.'|'.$pan.'|'.($name ?? '').'|'.($dateOfBirth ?? ''));
        $request = $this->providerRequest($user, $verification, 'PAN', $providerKey, $hash);
        if ($request->status === 'completed' && $request->normalized_response) return $this->applyPan($verification, $this->storedResult($providerKey, 'PAN', $request->normalized_response), $pan);

        $started = microtime(true);
        $request->update(['status' => 'processing', 'started_at' => now(), 'request_reference' => (string) Str::uuid()]);
        try {
            $result = $provider->verifyPan($pan, $name, $dateOfBirth);
            $this->completeRequest($request, $result, $started);
            return $this->applyPan($verification, $result, $pan);
        } catch (Throwable $e) {
            throw $this->recordProviderFailure($request, $verification, 'PAN', $started, $e);
        }
    }

    public function approve(BusinessVerification $verification, User $admin, string $reason): BusinessVerification
    {
        abort_unless(GeneralSettings::bool('kyb_allow_admin_override', true), 403, 'Admin KYB override is disabled.');
        $verification->update(['overall_kyb_status' => 'VERIFIED', 'approved_by' => $admin->id, 'approved_at' => now(), 'verified_at' => now(), 'expires_at' => now()->addDays(GeneralSettings::int('kyb_bank_validity_days', 365)), 'review_reason' => $reason, 'rejection_reason' => null]);
        $verification->vendor?->update(['gst_number' => $verification->gstin, 'gst_status' => 'valid', 'bank_status' => 'valid', 'status' => 'approved', 'approved_at' => now()]);
        AuditLogger::write('KYB_APPROVED', 'business_verification', (string) $verification->id, ['reason' => $reason]);
        app(NotificationService::class)->push($verification->user, 'KYB_APPROVED', 'Business verification approved', 'Your business verification is now complete.', ['verification_id' => $verification->id], "kyb:{$verification->id}:approved");
        return $verification->fresh();
    }

    public function reject(BusinessVerification $verification, User $admin, string $reason): BusinessVerification
    {
        $verification->update(['overall_kyb_status' => 'REJECTED', 'rejected_by' => $admin->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);
        $verification->vendor?->update(['status' => 'rejected', 'rejection_reason' => $reason]);
        AuditLogger::write('KYB_REJECTED', 'business_verification', (string) $verification->id, ['reason' => $reason]);
        app(NotificationService::class)->push($verification->user, 'KYB_REJECTED', 'Business verification requires changes', $reason, ['verification_id' => $verification->id], "kyb:{$verification->id}:rejected:{$verification->updated_at?->timestamp}");
        return $verification->fresh();
    }

    private function providerRequest(User $user, BusinessVerification $verification, string $type, string $provider, string $hash): VerificationProviderRequest
    {
        return VerificationProviderRequest::firstOrCreate(['user_id' => $user->id, 'verification_type' => $type, 'request_hash' => $hash], ['business_verification_id' => $verification->id, 'provider' => $provider, 'status' => 'created']);
    }

    private function completeRequest(VerificationProviderRequest $request, NormalizedVerificationResult $result, float $started): void
    {
        $request->update(['status' => 'completed', 'provider' => strtoupper($result->provider), 'provider_reference' => $result->referenceId, 'normalized_response' => json_encode($result->toArray()), 'completed_at' => now(), 'latency_ms' => (int) ((microtime(true) - $started) * 1000)]);
        if ($result->isVerified()) {
            GeneralSetting::updateOrCreate(['key' => 'verification_'.strtolower($result->provider).'_last_successful_at'], ['value' => now()->toIso8601String()]);
        }
    }

    private function recordProviderFailure(VerificationProviderRequest $request, BusinessVerification $verification, string $type, float $started, Throwable $exception): VerificationProviderException
    {
        $errorCode = $exception instanceof VerificationProviderException ? $exception->errorCode : 'PROVIDER_TEMPORARY_ERROR';
        $status = $exception instanceof VerificationProviderException ? $exception->httpStatus : 503;
        $request->update(['status' => 'failed', 'error_code' => $errorCode, 'completed_at' => now(), 'latency_ms' => (int) ((microtime(true) - $started) * 1000)]);
        $updates = match ($type) {
            'GSTIN' => ['gstin_status' => 'GSTIN_PENDING', 'overall_kyb_status' => 'GSTIN_PENDING'],
            'PAN' => ['pan_status' => 'PAN_PENDING'],
            default => ['bank_verification_status' => 'BANK_PENDING', 'overall_kyb_status' => 'BANK_PENDING'],
        };
        $verification->update($updates + ['last_error_code' => $errorCode]);
        $auditAction = match ($type) {
            'GSTIN' => 'GST_VERIFICATION_UNAVAILABLE',
            'PAN' => 'PAN_VERIFICATION_UNAVAILABLE',
            default => 'BANK_VERIFICATION_UNAVAILABLE',
        };
        AuditLogger::write($auditAction, 'business_verification', (string) $verification->id, ['error_code' => $errorCode]);
        return $exception instanceof VerificationProviderException ? $exception : new VerificationProviderException($errorCode, ucfirst(strtolower($type)).' verification is temporarily unavailable. Please retry safely.', $status);
    }

    private function applyGstin(BusinessVerification $verification, NormalizedVerificationResult $result): BusinessVerification
    {
        $data = $result->data; $active = $result->isVerified();
        $verification->update([
            'gstin' => strtoupper((string) ($data['gstin'] ?? $verification->gstin)), 'gstin_status' => $active ? 'GSTIN_VERIFIED' : 'GSTIN_FAILED', 'gstin_provider' => strtoupper($result->provider), 'gstin_reference_id' => $result->referenceId, 'gstin_verified_at' => $active ? now() : null,
            'legal_business_name' => $data['legal_business_name'] ?? null, 'trade_business_name' => $data['trade_business_name'] ?? null, 'constitution_of_business' => $data['constitution_of_business'] ?? null, 'taxpayer_type' => $data['taxpayer_type'] ?? null, 'gst_registration_status' => $data['gst_registration_status'] ?? $result->rawStatus, 'gst_registration_date' => $data['gst_registration_date'] ?? null, 'gst_registered_address' => $data['gst_registered_address'] ?? null, 'business_activities' => $data['business_activities'] ?? [], 'last_error_code' => $active ? null : ($result->errorCode ?: 'GSTIN_INACTIVE'),
        ]);
        $this->decide($verification->fresh());
        AuditLogger::write($active ? 'GST_VERIFIED' : 'GST_FAILED', 'business_verification', (string) $verification->id, ['provider' => strtoupper($result->provider), 'provider_reference' => $result->referenceId, 'gst_status' => $result->rawStatus]);
        app(NotificationService::class)->push($verification->user, $active ? 'GST_VERIFIED' : 'GST_FAILED', $active ? 'GSTIN verified' : 'GSTIN verification failed', $active ? 'Your GSTIN business details were verified.' : 'The GSTIN is not active or could not be verified.', ['verification_id' => $verification->id], "kyb:{$verification->id}:gst:{$verification->gstin_status}");
        return $verification->fresh();
    }

    private function applyPan(BusinessVerification $verification, NormalizedVerificationResult $result, string $pan): BusinessVerification
    {
        $data = $result->data;
        $valid = $result->isVerified();
        $verification->update([
            'pan_masked' => $this->maskPan($pan),
            'pan_status' => $valid ? 'PAN_VERIFIED' : 'PAN_FAILED',
            'pan_provider' => strtoupper($result->provider),
            'kyc_provider' => strtoupper($result->provider),
            'pan_reference_id' => $result->referenceId,
            'pan_verified_at' => $valid ? now() : null,
            'pan_name' => $data['registered_name'] ?? null,
            'last_error_code' => $valid ? null : ($result->errorCode ?: 'PAN_INVALID'),
        ]);
        AuditLogger::write($valid ? 'PAN_VERIFIED' : 'PAN_FAILED', 'business_verification', (string) $verification->id, ['provider' => strtoupper($result->provider), 'provider_reference' => $result->referenceId]);
        return $verification->fresh();
    }

    private function applyBank(BusinessVerification $verification, NormalizedVerificationResult $result, string $account, string $ifsc): BusinessVerification
    {
        $data = $result->data; $valid = $result->isVerified(); $score = is_numeric($data['name_match_score'] ?? null) ? (float) $data['name_match_score'] : null;
        $verification->update([
            'bank_account_masked' => $this->mask($account), 'bank_account_encrypted' => $account, 'ifsc' => $ifsc, 'bank_verification_status' => $valid ? 'BANK_VERIFIED' : 'BANK_FAILED', 'bank_provider' => strtoupper($result->provider), 'bank_reference_id' => $result->referenceId, 'bank_name' => $data['bank_name'] ?? null, 'bank_branch' => $data['branch'] ?? null, 'bank_city' => $data['city'] ?? null, 'bank_account_holder_name' => $data['name_at_bank'] ?? null, 'bank_name_match_score' => $score, 'bank_name_match_result' => $data['name_match_result'] ?? null, 'business_bank_match_status' => $this->matchStatus($score, $valid), 'last_error_code' => $valid ? null : ($result->errorCode ?: 'BANK_ACCOUNT_INVALID'),
        ]);
        $this->decide($verification->fresh());
        AuditLogger::write($valid ? 'BANK_VERIFIED' : 'BANK_FAILED', 'business_verification', (string) $verification->id, ['provider' => strtoupper($result->provider), 'provider_reference' => $result->referenceId, 'name_match_score' => $score]);
        app(NotificationService::class)->push($verification->user, $valid ? 'BANK_VERIFIED' : 'BANK_FAILED', $valid ? 'Bank account verified' : 'Bank verification failed', $valid ? 'Your bank account details were verified.' : 'The bank account could not be verified.', ['verification_id' => $verification->id], "kyb:{$verification->id}:bank:{$verification->bank_verification_status}:{$verification->bank_reference_id}");
        return $verification->fresh();
    }

    private function storedResult(string $provider, string $type, string $json): NormalizedVerificationResult
    {
        $stored = json_decode($json, true) ?: [];
        if (isset($stored['data']) && is_array($stored['data'])) return new NormalizedVerificationResult((string) ($stored['status'] ?? 'PENDING'), strtoupper((string) ($stored['provider'] ?? $provider)), $stored['reference_id'] ?? null, $stored['data'], $stored['raw_status'] ?? null, $stored['error_code'] ?? null, $stored['verification_type'] ?? null);
        if ($type === 'GSTIN') {
            $active = (bool) ($stored['valid'] ?? false) && strtoupper((string) ($stored['gst_in_status'] ?? '')) === 'ACTIVE';
            return new NormalizedVerificationResult($active ? 'VERIFIED' : 'FAILED', $provider, $stored['reference_id'] ?? null, ['gstin' => strtoupper((string) ($stored['GSTIN'] ?? '')), 'legal_business_name' => $stored['legal_name_of_business'] ?? null, 'trade_business_name' => $stored['trade_name_of_business'] ?? null, 'constitution_of_business' => $stored['constitution_of_business'] ?? null, 'taxpayer_type' => $stored['taxpayer_type'] ?? null, 'gst_registration_status' => $stored['gst_in_status'] ?? null, 'gst_registration_date' => $stored['date_of_registration'] ?? null, 'gst_registered_address' => $stored['principal_place_split_address'] ?? ['address' => $stored['principal_place_address'] ?? null], 'business_activities' => $stored['nature_of_business_activities'] ?? []], $stored['gst_in_status'] ?? null, $active ? null : 'GSTIN_INACTIVE');
        }
        if ($type === 'PAN') {
            $valid = (bool) ($stored['valid'] ?? false);
            return new NormalizedVerificationResult($valid ? 'VERIFIED' : 'FAILED', $provider, $stored['reference_id'] ?? null, ['pan' => $stored['pan'] ?? '', 'registered_name' => $stored['registered_name'] ?? null, 'name_match_score' => $stored['name_match_score'] ?? null, 'name_match_result' => $stored['name_match_result'] ?? null], $stored['status'] ?? null, $valid ? null : 'PAN_INVALID', 'PAN');
        }
        $valid = strtoupper((string) ($stored['account_status'] ?? '')) === 'VALID';
        return new NormalizedVerificationResult($valid ? 'VERIFIED' : 'FAILED', $provider, $stored['reference_id'] ?? null, ['account_status' => $stored['account_status'] ?? null, 'name_at_bank' => $stored['name_at_bank'] ?? null, 'bank_name' => $stored['bank_name'] ?? null, 'branch' => $stored['branch'] ?? null, 'city' => $stored['city'] ?? null, 'name_match_score' => $stored['name_match_score'] ?? null, 'name_match_result' => $stored['name_match_result'] ?? null], $stored['account_status'] ?? null, $valid ? null : 'BANK_ACCOUNT_INVALID');
    }

    private function decide(BusinessVerification $verification): void
    {
        if ($verification->gstin_status === 'GSTIN_FAILED' || $verification->bank_verification_status === 'BANK_FAILED') $status = 'REJECTED';
        elseif ($verification->gstin_status !== 'GSTIN_VERIFIED') $status = 'GSTIN_PENDING';
        elseif ($verification->bank_verification_status !== 'BANK_VERIFIED') $status = 'BANK_PENDING';
        elseif (! GeneralSettings::bool('business_bank_name_match_required', true)) $status = 'VERIFIED';
        elseif ((float) $verification->bank_name_match_score >= GeneralSettings::int('kyb_auto_approve_match_score', 85)) $status = 'VERIFIED';
        elseif ((float) $verification->bank_name_match_score >= GeneralSettings::int('kyb_review_match_score', 60)) $status = 'REVIEW_REQUIRED';
        else $status = 'REVIEW_REQUIRED';
        $updates = ['overall_kyb_status' => $status];
        if ($status === 'VERIFIED') $updates += ['verified_at' => now(), 'expires_at' => now()->addDays(GeneralSettings::int('kyb_bank_validity_days', 365))];
        $verification->update($updates);
    }

    private function matchStatus(?float $score, bool $valid): string
    {
        if (! $valid || $score === null) return 'REVIEW_REQUIRED';
        if ($score >= GeneralSettings::int('kyb_auto_approve_match_score', 85)) return 'AUTO_APPROVED';
        if ($score >= GeneralSettings::int('kyb_review_match_score', 60)) return 'REVIEW_REQUIRED';
        return 'REJECTED';
    }

    private function mask(string $account): string
    {
        return str_repeat('X', max(0, strlen($account) - 4)).substr($account, -4);
    }

    private function maskPan(string $pan): string
    {
        return substr($pan, 0, 2).str_repeat('X', max(0, strlen($pan) - 4)).substr($pan, -2);
    }
}
