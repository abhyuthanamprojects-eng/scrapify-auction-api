<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GeneralSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VerificationProviderRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VerificationProviderArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.sandbox_verification.enabled', true);
        config()->set('services.sandbox_verification.api_key', 'sandbox-key');
        config()->set('services.sandbox_verification.api_secret', 'sandbox-secret');
        config()->set('services.cashfree_secure_id.enabled', true);
        config()->set('services.cashfree_secure_id.client_id', 'cashfree-client');
        config()->set('services.cashfree_secure_id.client_secret', 'cashfree-secret');
        config()->set('services.cashfree_secure_id.base_url', 'https://sandbox.cashfree.com/verification');
        Cache::flush();
    }

    public function test_sandbox_is_the_default_for_gst_and_kyc_and_returns_normalized_data(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        Http::fake([
            '*test-api.sandbox.co.in/authenticate' => Http::response(['data' => ['access_token' => 'sandbox-token']]),
            '*test-api.sandbox.co.in/gst/compliance/public/gstin/verify' => Http::response(['transaction_id' => 'sandbox-gst-ref', 'data' => ['data' => ['gstin' => '29AAICP2912R1ZR', 'legalName' => 'Acme Technologies', 'status' => 'Active', 'validGstin' => true, 'stateName' => 'Maharashtra', 'stateCode' => '27'], 'status_cd' => '1']]),
            '*test-api.sandbox.co.in/kyc/pan/verify' => Http::response(['transaction_id' => 'sandbox-pan-ref', 'data' => ['status' => 'valid', 'pan' => 'AAACB1234N', 'name_as_per_pan_match' => true, 'date_of_birth_match' => true]]),
        ]);

        $this->postJson('/api/v1/kyb/gstin/verify', ['gstin' => '29AAICP2912R1ZR'])->assertOk()
            ->assertJsonPath('data.gstin_status', 'GSTIN_VERIFIED')
            ->assertJsonPath('data.gstin_provider', 'SANDBOX');
        $this->postJson('/api/v1/kyb/pan/verify', ['pan' => 'AAACB1234N', 'name' => 'Acme Technologies', 'date_of_birth' => '1980-01-01'])
            ->assertOk()->assertJsonPath('data.kyc_provider', 'SANDBOX')->assertJsonPath('data.pan_status', 'PAN_VERIFIED');

        $this->assertSame(['SANDBOX', 'SANDBOX'], VerificationProviderRequest::query()->orderBy('verification_type')->pluck('provider')->all());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/gst/compliance/public/gstin/verify'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/kyc/pan/verify')
            && $request->data()['@entity'] === 'in.co.sandbox.kyc.pan_verification.request'
            && $request->data()['date_of_birth'] === '01/01/1980');
    }

    public function test_active_provider_failure_does_not_fallback_to_cashfree(): void
    {
        GeneralSetting::create(['key' => 'gst_verification_provider', 'value' => 'sandbox']);
        GeneralSetting::create(['key' => 'kyc_verification_provider', 'value' => 'sandbox']);
        $user = $this->user();
        Sanctum::actingAs($user);
        Http::fake([
            '*test-api.sandbox.co.in/authenticate' => Http::response(['message' => 'unavailable'], 503),
            '*sandbox.cashfree.com/verification/*' => Http::response(['valid' => true, 'reference_id' => 'cashfree-must-not-run']),
        ]);

        $this->postJson('/api/v1/kyb/gstin/verify', ['gstin' => '29AAICP2912R1ZR'])
            ->assertStatus(503)->assertJsonPath('error.code', 'PROVIDER_UNAVAILABLE');
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cashfree'));
        $this->assertSame('SANDBOX', VerificationProviderRequest::query()->value('provider'));
    }

    public function test_sandbox_bank_verification_authenticates_and_uses_penniless_endpoint(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        Http::fake([
            '*test-api.sandbox.co.in/authenticate' => Http::response(['data' => ['access_token' => 'sandbox-bank-token']]),
            '*test-api.sandbox.co.in/bank/HDFC0000060/accounts/123456789/penniless-verify*' => Http::response([
                'code' => 200,
                'transaction_id' => 'sandbox-bank-ref',
                'data' => [
                    'account_exists' => true,
                    'name_at_bank' => 'ACME TECHNOLOGIES',
                ],
            ]),
            '*sandbox.cashfree.com/verification/*' => Http::response(['valid' => true, 'reference_id' => 'cashfree-must-not-run']),
        ]);

        $this->postJson('/api/v1/kyb/bank/verify', [
            'bank_account' => '123456789',
            'bank_account_confirmation' => '123456789',
            'ifsc' => 'HDFC0000060',
            'name' => 'Acme Technologies',
        ])->assertOk()
            ->assertJsonPath('data.bank_verification_status', 'BANK_VERIFIED')
            ->assertJsonPath('data.bank_provider', 'SANDBOX')
            ->assertJsonPath('data.kyc_provider', 'SANDBOX')
            ->assertJsonPath('data.bank_reference_id', 'sandbox-bank-ref');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/authenticate'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/penniless-verify')
            && $request->header('authorization') === ['sandbox-bank-token']
            && $request->header('x-api-key') === ['sandbox-key']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cashfree'));
        $this->assertDatabaseHas('verification_provider_requests', [
            'verification_type' => 'BANK',
            'provider' => 'SANDBOX',
            'provider_reference' => 'sandbox-bank-ref',
            'status' => 'completed',
        ]);
    }

    public function test_sandbox_reauthenticates_once_when_a_cached_token_is_rejected(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $authenticationCalls = 0;
        $bankCalls = 0;
        Http::fake(function ($request) use (&$authenticationCalls, &$bankCalls) {
            if (str_contains($request->url(), '/authenticate')) {
                $authenticationCalls++;
                return Http::response(['data' => ['access_token' => 'fresh-token-'.$authenticationCalls]]);
            }
            if (str_contains($request->url(), '/penniless-verify')) {
                $bankCalls++;
                return $bankCalls === 1
                    ? Http::response(['message' => 'expired'], 401)
                    : Http::response(['code' => 200, 'transaction_id' => 'refreshed-bank-ref', 'data' => ['account_exists' => true]]);
            }
            return Http::response([], 404);
        });

        $this->postJson('/api/v1/kyb/bank/verify', [
            'bank_account' => '123456789',
            'bank_account_confirmation' => '123456789',
            'ifsc' => 'HDFC0000060',
        ])->assertOk()->assertJsonPath('data.bank_verification_status', 'BANK_VERIFIED');

        $this->assertSame(2, $authenticationCalls);
        $this->assertSame(2, $bankCalls);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/penniless-verify')
            && $request->header('authorization') === ['fresh-token-2']);
    }

    public function test_gst_and_kyc_can_use_different_active_providers(): void
    {
        GeneralSetting::create(['key' => 'gst_verification_provider', 'value' => 'sandbox']);
        GeneralSetting::create(['key' => 'kyc_verification_provider', 'value' => 'cashfree']);
        $user = $this->user();
        Sanctum::actingAs($user);
        Http::fake([
            '*test-api.sandbox.co.in/authenticate' => Http::response(['data' => ['access_token' => 'sandbox-token-3']]),
            '*test-api.sandbox.co.in/gst/compliance/public/gstin/verify' => Http::response(['data' => ['data' => ['gstin' => '29AAICP2912R1ZR', 'status' => 'Active', 'validGstin' => true], 'status_cd' => '1']]),
            '*sandbox.cashfree.com/verification/pan' => Http::response(['reference_id' => 'cashfree-pan-ref', 'valid' => true, 'pan' => 'AAACB1234N', 'registered_name' => 'ACME TECHNOLOGIES']),
        ]);

        $this->postJson('/api/v1/kyb/gstin/verify', ['gstin' => '29AAICP2912R1ZR'])->assertOk()->assertJsonPath('data.gstin_provider', 'SANDBOX');
        $this->postJson('/api/v1/kyb/pan/verify', ['pan' => 'AAACB1234N', 'name' => 'Acme Technologies', 'date_of_birth' => '1980-01-01'])->assertOk()->assertJsonPath('data.kyc_provider', 'CASHFREE');

        $this->assertSame(['SANDBOX', 'CASHFREE'], VerificationProviderRequest::query()->orderBy('id')->pluck('provider')->all());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'cashfree.com/verification/gstin'));
    }

    public function test_provider_switch_creates_new_history_and_preserves_previous_provider(): void
    {
        GeneralSetting::create(['key' => 'gst_verification_provider', 'value' => 'cashfree']);
        GeneralSetting::create(['key' => 'kyc_verification_provider', 'value' => 'cashfree']);
        $user = $this->user();
        Sanctum::actingAs($user);
        Http::fake([
            '*sandbox.cashfree.com/verification/gstin' => Http::response(['reference_id' => 'cashfree-ref', 'GSTIN' => '29AAICP2912R1ZR', 'gst_in_status' => 'Active', 'valid' => true]),
            '*test-api.sandbox.co.in/authenticate' => Http::response(['data' => ['access_token' => 'sandbox-token-2']]),
            '*test-api.sandbox.co.in/gst/compliance/public/gstin/verify' => Http::response(['data' => ['data' => ['gstin' => '29AAICP2912R1ZR', 'status' => 'Active', 'validGstin' => true], 'status_cd' => '1']]),
        ]);

        $this->postJson('/api/v1/kyb/gstin/verify', ['gstin' => '29AAICP2912R1ZR'])->assertOk()->assertJsonPath('data.gstin_provider', 'CASHFREE');
        GeneralSetting::where('key', 'gst_verification_provider')->update(['value' => 'sandbox']);
        $this->postJson('/api/v1/kyb/gstin/verify', ['gstin' => '29AAICP2912R1ZR'])->assertOk()->assertJsonPath('data.gstin_provider', 'SANDBOX');

        $this->assertSame(['CASHFREE', 'SANDBOX'], VerificationProviderRequest::query()->orderBy('id')->pluck('provider')->all());
        $this->assertSame('SANDBOX', \App\Models\BusinessVerification::query()->value('gstin_provider'));
    }

    public function test_admin_settings_mask_secrets_and_reject_unconfigured_activation(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/admin/integration-settings', [
            'gst_verification_provider' => 'CASHFREE',
            'cashfree_secure_id_enabled' => false,
        ])->assertStatus(422)->assertJsonPath('error.code', 'PROVIDER_NOT_CONFIGURED');

        $response = $this->putJson('/api/v1/admin/integration-settings', [
            'gst_verification_provider' => 'SANDBOX',
            'kyc_verification_provider' => 'SANDBOX',
            'sandbox_verification_enabled' => true,
            'sandbox_verification_api_key' => 'fresh-sandbox-key',
            'sandbox_verification_api_secret' => 'fresh-sandbox-secret',
        ])->assertOk();

        $response->assertJsonMissing(['fresh-sandbox-key', 'fresh-sandbox-secret'])
            ->assertJsonPath('gst_verification_provider', 'SANDBOX');
        $this->putJson('/api/v1/admin/integration-settings', [
            'kyc_verification_provider' => 'CASHFREE',
            'cashfree_secure_id_enabled' => true,
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'VERIFICATION_PROVIDER_CHANGED', 'entity_type' => 'verification_settings', 'entity_id' => 'kyc']);
        $this->assertNotSame('fresh-sandbox-secret', GeneralSetting::where('key', 'sandbox_verification_api_secret')->value('value'));
    }

    public function test_admin_can_test_active_kyc_provider_with_pan_inputs_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);
        Http::fake([
            '*test-api.sandbox.co.in/authenticate' => Http::response(['data' => ['access_token' => 'sandbox-test-token']]),
            '*test-api.sandbox.co.in/kyc/pan/verify' => Http::response(['data' => ['valid' => true, 'pan' => 'AAACB1234N', 'registered_name' => 'ACME TECHNOLOGIES']]),
        ]);

        $this->postJson('/api/v1/admin/integration-settings/test-verification', [
            'verification_type' => 'KYC',
            'pan' => 'AAACB1234N',
            'name' => 'Acme Technologies',
            'date_of_birth' => '1980-01-01',
        ])->assertOk()
            ->assertJsonPath('data.provider', 'sandbox')
            ->assertJsonPath('data.verification_type', 'PAN')
            ->assertJsonPath('data.verified', true)
            ->assertJsonMissing(['sandbox-key', 'sandbox-secret']);
    }

    private function user(): User
    {
        $user = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $vendor = Vendor::create(['user_id' => $user->id, 'company_name' => 'Acme Technologies', 'contact_name' => 'A Buyer', 'email' => $user->email, 'phone' => '9999999999', 'status' => 'approved']);
        $user->update(['vendor_id' => $vendor->id]);
        return $user->fresh();
    }
}
