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
        config()->set('services.cashfree_secure_id.enabled', true);
        config()->set('services.cashfree_secure_id.client_id', 'sandbox-client');
        config()->set('services.cashfree_secure_id.client_secret', 'sandbox-secret');
        config()->set('services.cashfree_secure_id.base_url', 'https://sandbox.cashfree.com/verification');
        GeneralSetting::updateOrCreate(['key' => 'gst_verification_provider'], ['value' => 'cashfree']);
        GeneralSetting::updateOrCreate(['key' => 'kyc_verification_provider'], ['value' => 'cashfree']);
        GeneralSetting::updateOrCreate(['key' => 'bank_verification_provider'], ['value' => 'cashfree']);
    }

    public function test_gstin_and_bank_are_provider_backed_normalized_and_idempotent(): void
    {
        Http::fake([
            '*sandbox.cashfree.com/verification/gstin*' => Http::response(['reference_id' => 11, 'GSTIN' => '29AAICP2912R1ZR', 'legal_name_of_business' => 'ACME TECHNOLOGIES PRIVATE LIMITED', 'trade_name_of_business' => 'ACME', 'gst_in_status' => 'Active', 'valid' => true], 200),
            '*sandbox.cashfree.com/verification/bank-account/sync*' => Http::response(['reference_id' => 22, 'account_status' => 'VALID', 'name_at_bank' => 'ACME TECHNOLOGIES PVT LTD', 'bank_name' => 'YES BANK', 'branch' => 'CENTRAL', 'city' => 'MUMBAI', 'name_match_score' => '90.00', 'name_match_result' => 'GOOD_PARTIAL_MATCH'], 200),
        ]);
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/kyb/gstin/verify', ['gstin' => '29AAICP2912R1ZR'])->assertOk()->assertJsonPath('data.gstin_status', 'GSTIN_VERIFIED');
        $this->postJson('/api/v1/kyb/gstin/verify', ['gstin' => '29AAICP2912R1ZR'])->assertOk();
        $this->postJson('/api/v1/kyb/bank/verify', ['bank_account' => '26291800001191', 'bank_account_confirmation' => '26291800001191', 'ifsc' => 'YESB0000001', 'name' => 'Acme Technologies', 'phone' => '9999999999'])->assertOk()->assertJsonPath('data.overall_kyb_status', 'VERIFIED')->assertJsonPath('data.bank_account_masked', 'XXXXXXXXXX1191');

        Http::assertSentCount(2);
        $verification = BusinessVerification::where('user_id', $user->id)->firstOrFail();
        $this->assertNotSame('26291800001191', (string) $verification->getRawOriginal('bank_account_encrypted'));
        $this->assertSame(2, VerificationProviderRequest::where('user_id', $user->id)->count());
    }

    public function test_moderate_match_requires_review_and_provider_outage_is_not_rejection(): void
    {
        Http::fake(['*sandbox.cashfree.com/verification/gstin*' => Http::response(['reference_id' => 31, 'GSTIN' => '29AAICP2912R1ZR', 'gst_in_status' => 'Active', 'valid' => true]), '*sandbox.cashfree.com/verification/bank-account/sync*' => Http::response(['reference_id' => 32, 'account_status' => 'VALID', 'name_at_bank' => 'DIFFERENT NAME', 'name_match_score' => '70', 'name_match_result' => 'MODERATE_MATCH'])]);
        $user = $this->user(); Sanctum::actingAs($user);
        $this->postJson('/api/v1/kyb/gstin/verify', ['gstin' => '29AAICP2912R1ZR'])->assertOk();
        $this->postJson('/api/v1/kyb/bank/verify', ['bank_account' => '26291800001191', 'bank_account_confirmation' => '26291800001191', 'ifsc' => 'YESB0000001'])->assertOk()->assertJsonPath('data.overall_kyb_status', 'REVIEW_REQUIRED');

        config(['services.cashfree_secure_id.base_url' => 'https://outage.test/verification']);
        Http::fake(['outage.test/verification/bank-account/sync' => Http::response([], 503)]);
        $this->postJson('/api/v1/kyb/bank/verify', ['bank_account' => '26291800001192', 'bank_account_confirmation' => '26291800001192', 'ifsc' => 'YESB0000001'])->assertStatus(503)->assertJsonPath('error.code', 'PROVIDER_UNAVAILABLE');
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
