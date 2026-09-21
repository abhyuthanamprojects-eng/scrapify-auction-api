<?php

namespace Tests\Feature;

use App\Models\BusinessVerification;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VerificationProviderRequest;
use App\Models\GeneralSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'buyer'): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $vendor = Vendor::create(['user_id' => $user->id, 'company_name' => 'Acme Technologies', 'contact_name' => 'A Buyer', 'email' => $user->email, 'phone' => '9999999999', 'status' => 'approved']);
        $user->update(['vendor_id' => $vendor->id]);
        return $user->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();
        GeneralSetting::updateOrCreate(['key' => 'sandbox_verification_enabled'], ['value' => '1']);
        GeneralSetting::updateOrCreate(['key' => 'sandbox_verification_api_key'], ['value' => \Illuminate\Support\Facades\Crypt::encryptString('sandbox-key')]);
        GeneralSetting::updateOrCreate(['key' => 'sandbox_verification_api_secret'], ['value' => \Illuminate\Support\Facades\Crypt::encryptString('sandbox-secret')]);
    }

    public function test_gstin_and_bank_are_provider_backed_normalized_and_idempotent(): void
    {
        Http::fake([
            '*test-api.sandbox.co.in/authenticate' => Http::response(['data' => ['access_token' => 'sandbox-token']]),
            '*test-api.sandbox.co.in/gst/compliance/public/gstin/verify' => Http::response(['transaction_id' => 'sandbox-gst-ref', 'data' => ['data' => ['gstin' => '29AAICP2912R1ZR', 'legalName' => 'ACME TECHNOLOGIES PRIVATE LIMITED', 'status' => 'Active', 'validGstin' => true], 'status_cd' => '1']]),
            '*test-api.sandbox.co.in/bank/YESB0000001' => Http::response(['data' => ['IFSC' => 'YESB0000001', 'BANK' => 'Yes Bank', 'BRANCH' => 'Sandbox Branch', 'CITY' => 'Bengaluru', 'STATE' => 'Karnataka']]),
            '*test-api.sandbox.co.in/bank/*/accounts/*/penniless-verify*' => Http::response(['code' => 200, 'transaction_id' => 'sandbox-bank-ref', 'data' => ['account_exists' => true, 'name_at_bank' => 'ACME TECHNOLOGIES PVT LTD']]),
        ]);
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/kyb/gstin/verify', ['gstin' => '29AAICP2912R1ZR'])->assertOk()->assertJsonPath('data.gstin_status', 'GSTIN_VERIFIED');
        $this->postJson('/api/v1/kyb/gstin/verify', ['gstin' => '29AAICP2912R1ZR'])->assertOk();
        $this->postJson('/api/v1/kyb/bank/verify', ['bank_account' => '26291800001191', 'bank_account_confirmation' => '26291800001191', 'ifsc' => 'YESB0000001', 'name' => 'Acme Technologies', 'phone' => '9999999999'])->assertOk()->assertJsonPath('data.bank_verification_status', 'BANK_VERIFIED')->assertJsonPath('data.bank_account_masked', 'XXXXXXXXXX1191');

        $verification = BusinessVerification::where('user_id', $user->id)->firstOrFail();
        $this->assertNotSame('26291800001191', (string) $verification->getRawOriginal('bank_account_encrypted'));
        $this->assertSame(2, VerificationProviderRequest::where('user_id', $user->id)->count());
    }

    public function test_moderate_match_requires_review_and_provider_outage_is_not_rejection(): void
    {
        Http::fake([
            '*test-api.sandbox.co.in/authenticate' => Http::response(['data' => ['access_token' => 'sandbox-token']]),
            '*test-api.sandbox.co.in/gst/compliance/public/gstin/verify' => Http::response(['transaction_id' => 'sandbox-gst-ref', 'data' => ['data' => ['gstin' => '29AAICP2912R1ZR', 'status' => 'Active', 'validGstin' => true], 'status_cd' => '1']]),
            '*test-api.sandbox.co.in/bank/YESB0000001' => Http::response(['data' => ['IFSC' => 'YESB0000001', 'BANK' => 'Yes Bank', 'BRANCH' => 'Sandbox Branch', 'CITY' => 'Bengaluru', 'STATE' => 'Karnataka']]),
            '*test-api.sandbox.co.in/bank/*/accounts/*/penniless-verify*' => Http::response(['code' => 200, 'transaction_id' => 'sandbox-bank-ref', 'data' => ['account_exists' => true, 'name_at_bank' => 'DIFFERENT NAME']]),
        ]);
        $user = $this->user(); Sanctum::actingAs($user);
        $this->postJson('/api/v1/kyb/gstin/verify', ['gstin' => '29AAICP2912R1ZR'])->assertOk();
        $this->postJson('/api/v1/kyb/bank/verify', ['bank_account' => '26291800001191', 'bank_account_confirmation' => '26291800001191', 'ifsc' => 'YESB0000001'])->assertOk()->assertJsonPath('data.overall_kyb_status', 'REVIEW_REQUIRED');

        Http::fake(fn () => Http::response([], 503));
        $outageRes = $this->postJson('/api/v1/kyb/bank/verify', ['bank_account' => '26291800001192', 'bank_account_confirmation' => '26291800001192', 'ifsc' => 'YESB0000002']);
        $outageRes->assertStatus(503)->assertJsonPath('error.code', 'PROVIDER_UNAVAILABLE');
        $this->assertSame('BANK_PENDING', BusinessVerification::where('user_id', $user->id)->value('overall_kyb_status'));
    }

    public function test_kyb_status_is_user_scoped_and_admin_can_approve_review(): void
    {
        $user = $this->user();
        $other = $this->user();
        $verification = BusinessVerification::create(['user_id' => $user->id, 'vendor_id' => $user->vendor_id, 'role_type' => 'buyer', 'overall_kyb_status' => 'REVIEW_REQUIRED', 'gstin' => '29AAICP2912R1ZR']);
        Sanctum::actingAs($other);
        $this->getJson('/api/v1/kyb/status')->assertOk()->assertJsonPath('data.overall_kyb_status', 'NOT_STARTED');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/kyb/{$verification->id}/approve", ['reason' => 'Reviewed sandbox evidence'])->assertOk()->assertJsonPath('data.overall_kyb_status', 'VERIFIED');
    }
}
