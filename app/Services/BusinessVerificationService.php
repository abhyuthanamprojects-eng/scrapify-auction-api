<?php

namespace App\Services;

use App\Contracts\BusinessVerificationProviderInterface;
use App\Models\BusinessVerification;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VerificationProviderRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class BusinessVerificationService
{
    public function __construct(private BusinessVerificationProviderInterface $provider) {}

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
        return [
            'id' => $verification->id, 'role_type' => $verification->role_type,
            'gstin' => $verification->gstin, 'gstin_status' => $verification->gstin_status,
            'gstin_reference_id' => $verification->gstin_reference_id,
            'gstin_verified_at' => $verification->gstin_verified_at?->toIso8601String(),
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
        if ($verification->gstin === $gstin && $verification->gstin_status === 'GSTIN_VERIFIED' && $verification->gstin_verified_at?->addDays(GeneralSettings::int('kyb_gst_validity_days', 180))?->isFuture()) return $verification;
        if (! GeneralSettings::bool('kyb_allow_multiple_gstin_users', false) && BusinessVerification::where('gstin', $gstin)->where('user_id', '!=', $user->id)->whereIn('gstin_status', ['GSTIN_VERIFIED', 'VERIFIED', 'REVIEW_REQUIRED'])->exists()) {
            $verification->update(['gstin' => $gstin, 'gstin_status' => 'GSTIN_ALREADY_REGISTERED', 'overall_kyb_status' => 'REVIEW_REQUIRED', 'last_error_code' => 'GSTIN_ALREADY_REGISTERED']);
            AuditLogger::write('GSTIN_ALREADY_REGISTERED', 'business_verification', (string) $verification->id, ['user_id' => $user->id]);
            throw ValidationException::withMessages(['gstin' => 'This GSTIN is already associated with another account and requires Admin review.']);
        }
        $hash = hash('sha256', 'GSTIN|'.$gstin.'|'.($businessName ?? ''));
        $request = $this->providerRequest($user, $verification, 'GSTIN', $hash);
        if ($request->status === 'completed' && $request->normalized_response) return $this->applyGstin($verification, json_decode($request->normalized_response, true) ?: []);
        $started = microtime(true);
        $request->update(['status' => 'processing', 'started_at' => now(), 'request_reference' => (string) Str::uuid()]);
        try {
            $payload = $this->provider->verifyGstin($gstin, $businessName ?: $user->vendor?->company_name);
            $request->update(['status' => 'completed', 'provider_reference' => (string) ($payload['reference_id'] ?? ''), 'normalized_response' => json_encode($payload), 'completed_at' => now(), 'latency_ms' => (int) ((microtime(true) - $started) * 1000)]);
            return $this->applyGstin($verification, $payload);
        } catch (Throwable $e) {
            $request->update(['status' => 'failed', 'error_code' => str_starts_with($e->getMessage(), 'PROVIDER_HTTP_') ? $e->getMessage() : 'PROVIDER_TEMPORARY_ERROR', 'completed_at' => now(), 'latency_ms' => (int) ((microtime(true) - $started) * 1000)]);
            $verification->update(['gstin' => $gstin, 'gstin_status' => 'GSTIN_PENDING', 'overall_kyb_status' => 'GSTIN_PENDING', 'last_error_code' => 'VERIFICATION_TEMPORARILY_UNAVAILABLE']);
            AuditLogger::write('GST_VERIFICATION_UNAVAILABLE', 'business_verification', (string) $verification->id, ['error_code' => 'VERIFICATION_TEMPORARILY_UNAVAILABLE']);
            throw ValidationException::withMessages(['provider' => 'GSTIN verification is temporarily unavailable. Please retry safely.']);
        }
    }

    public function verifyBank(User $user, string $account, string $ifsc, ?string $name = null, ?string $phone = null): BusinessVerification
    {
        $account = trim($account); $ifsc = strtoupper(trim($ifsc)); $name = $name ? trim($name) : null;
        if (! preg_match('/^[A-Za-z0-9]{6,40}$/', $account)) throw ValidationException::withMessages(['bank_account' => 'Bank account must be 6 to 40 letters or digits.']);
        if (! preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) throw ValidationException::withMessages(['ifsc' => 'Enter a valid 11-character IFSC.']);
        $verification = $this->forUser($user);
        if (VerificationProviderRequest::where('user_id', $user->id)->where('verification_type', 'BANK')->where('created_at', '>=', now()->subHour())->count() >= GeneralSettings::int('kyb_max_provider_attempts_per_hour', 5)) throw ValidationException::withMessages(['provider' => 'Verification retry limit reached. Please try again later.']);
        $hash = hash('sha256', 'BANK|'.$account.'|'.$ifsc.'|'.($name ?? '').'|'.($phone ?? ''));
        $request = $this->providerRequest($user, $verification, 'BANK', $hash);
        if ($request->status === 'completed' && $request->normalized_response) return $this->applyBank($verification, json_decode($request->normalized_response, true) ?: [], $account, $ifsc);
        $started = microtime(true); $request->update(['status' => 'processing', 'started_at' => now(), 'request_reference' => (string) Str::uuid()]);
        try {
            $payload = $this->provider->verifyBankAccount($account, $ifsc, $name, $phone);
            $request->update(['status' => 'completed', 'provider_reference' => (string) ($payload['reference_id'] ?? ''), 'normalized_response' => json_encode($payload), 'completed_at' => now(), 'latency_ms' => (int) ((microtime(true) - $started) * 1000)]);
            return $this->applyBank($verification, $payload, $account, $ifsc);
        } catch (Throwable $e) {
            $request->update(['status' => 'failed', 'error_code' => 'PROVIDER_TEMPORARY_ERROR', 'completed_at' => now(), 'latency_ms' => (int) ((microtime(true) - $started) * 1000)]);
            $verification->update(['bank_verification_status' => 'BANK_PENDING', 'overall_kyb_status' => 'BANK_PENDING', 'last_error_code' => 'VERIFICATION_TEMPORARILY_UNAVAILABLE']);
            AuditLogger::write('BANK_VERIFICATION_UNAVAILABLE', 'business_verification', (string) $verification->id, ['error_code' => 'VERIFICATION_TEMPORARILY_UNAVAILABLE']);
            throw ValidationException::withMessages(['provider' => 'Bank verification is temporarily unavailable. Please retry safely.']);
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

    private function providerRequest(User $user, BusinessVerification $verification, string $type, string $hash): VerificationProviderRequest
    {
        return VerificationProviderRequest::firstOrCreate(['user_id' => $user->id, 'verification_type' => $type, 'request_hash' => $hash], ['business_verification_id' => $verification->id, 'provider' => 'CASHFREE', 'status' => 'created']);
    }

    private function applyGstin(BusinessVerification $v, array $p): BusinessVerification
    {
        $active = ($p['valid'] ?? false) && strtoupper((string) ($p['gst_in_status'] ?? '')) === 'ACTIVE';
        $v->update(['gstin' => strtoupper((string) ($p['GSTIN'] ?? $v->gstin)), 'gstin_status' => $active ? 'GSTIN_VERIFIED' : 'GSTIN_FAILED', 'gstin_reference_id' => $p['reference_id'] ?? null, 'gstin_verified_at' => $active ? now() : null, 'legal_business_name' => $p['legal_name_of_business'] ?? null, 'trade_business_name' => $p['trade_name_of_business'] ?? null, 'constitution_of_business' => $p['constitution_of_business'] ?? null, 'taxpayer_type' => $p['taxpayer_type'] ?? null, 'gst_registration_status' => $p['gst_in_status'] ?? null, 'gst_registration_date' => $p['date_of_registration'] ?? null, 'gst_registered_address' => $p['principal_place_split_address'] ?? ['address' => $p['principal_place_address'] ?? null], 'business_activities' => $p['nature_of_business_activities'] ?? [], 'last_error_code' => $active ? null : 'GSTIN_INACTIVE']);
        $this->decide($v->fresh());
        AuditLogger::write($active ? 'GST_VERIFIED' : 'GST_FAILED', 'business_verification', (string) $v->id, ['provider_reference' => $p['reference_id'] ?? null, 'gst_status' => $p['gst_in_status'] ?? null]);
        app(NotificationService::class)->push($v->user, $active ? 'GST_VERIFIED' : 'GST_FAILED', $active ? 'GSTIN verified' : 'GSTIN verification failed', $active ? 'Your GSTIN business details were verified.' : 'The GSTIN is not active or could not be verified.', ['verification_id' => $v->id], "kyb:{$v->id}:gst:{$v->gstin_status}");
        return $v->fresh();
    }

    private function applyBank(BusinessVerification $v, array $p, string $account, string $ifsc): BusinessVerification
    {
        $valid = strtoupper((string) ($p['account_status'] ?? '')) === 'VALID';
        $score = is_numeric($p['name_match_score'] ?? null) ? (float) $p['name_match_score'] : null;
        $v->update(['bank_account_masked' => $this->mask($account), 'bank_account_encrypted' => $account, 'ifsc' => $ifsc, 'bank_verification_status' => $valid ? 'BANK_VERIFIED' : 'BANK_FAILED', 'bank_reference_id' => $p['reference_id'] ?? null, 'bank_name' => $p['bank_name'] ?? null, 'bank_branch' => $p['branch'] ?? null, 'bank_city' => $p['city'] ?? null, 'bank_account_holder_name' => $p['name_at_bank'] ?? null, 'bank_name_match_score' => $score, 'bank_name_match_result' => $p['name_match_result'] ?? null, 'business_bank_match_status' => $this->matchStatus($score, $valid)]);
        $this->decide($v->fresh());
        AuditLogger::write($valid ? 'BANK_VERIFIED' : 'BANK_FAILED', 'business_verification', (string) $v->id, ['provider_reference' => $p['reference_id'] ?? null, 'name_match_score' => $score]);
        app(NotificationService::class)->push($v->user, $valid ? 'BANK_VERIFIED' : 'BANK_FAILED', $valid ? 'Bank account verified' : 'Bank verification failed', $valid ? 'Your bank account details were verified.' : 'The bank account could not be verified.', ['verification_id' => $v->id], "kyb:{$v->id}:bank:{$v->bank_verification_status}:{$v->bank_reference_id}");
        return $v->fresh();
    }

    private function decide(BusinessVerification $v): void
    {
        if ($v->gstin_status === 'GSTIN_FAILED' || $v->bank_verification_status === 'BANK_FAILED') $status = 'REJECTED';
        elseif ($v->gstin_status !== 'GSTIN_VERIFIED') $status = 'GSTIN_PENDING';
        elseif ($v->bank_verification_status !== 'BANK_VERIFIED') $status = 'BANK_PENDING';
        elseif (! GeneralSettings::bool('business_bank_name_match_required', true)) $status = 'VERIFIED';
        elseif ((float) $v->bank_name_match_score >= GeneralSettings::int('kyb_auto_approve_match_score', 85)) $status = 'VERIFIED';
        elseif ((float) $v->bank_name_match_score >= GeneralSettings::int('kyb_review_match_score', 60)) $status = 'REVIEW_REQUIRED';
        else $status = 'REVIEW_REQUIRED';
        $updates = ['overall_kyb_status' => $status];
        if ($status === 'VERIFIED') $updates += ['verified_at' => now(), 'expires_at' => now()->addDays(GeneralSettings::int('kyb_bank_validity_days', 365))];
        $v->update($updates);
    }

    private function matchStatus(?float $score, bool $valid): string
    {
        if (! $valid || $score === null) return 'REVIEW_REQUIRED';
        if ($score >= GeneralSettings::int('kyb_auto_approve_match_score', 85)) return 'AUTO_APPROVED';
        if ($score >= GeneralSettings::int('kyb_review_match_score', 60)) return 'REVIEW_REQUIRED';
        return 'REJECTED';
    }

    private function mask(string $account): string { return str_repeat('X', max(0, strlen($account) - 4)).substr($account, -4); }
}
