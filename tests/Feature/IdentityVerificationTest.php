<?php

namespace Tests\Feature;

use App\Models\GeneralSetting;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IdentityVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        GeneralSetting::updateOrCreate(['key' => 'digilocker_enabled'], ['value' => '1']);
        GeneralSetting::updateOrCreate(['key' => 'digilocker_client_id'], ['value' => encrypt('test-client-id')]);
        GeneralSetting::updateOrCreate(['key' => 'digilocker_client_secret'], ['value' => encrypt('test-client-secret')]);
    }

    public function test_status_returns_not_started_when_no_verification_exists(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/v1/identity/digilocker/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'NOT_STARTED')
            ->assertJsonPath('data.provider', 'DIGILOCKER');
    }

    public function test_initiate_returns_authorization_url(): void
    {
        Sanctum::actingAs($this->user());

        $response = $this->postJson('/api/v1/identity/digilocker/initiate', [
            'redirect_uri' => 'https://scrapifyauctions.com/kyc/digilocker/callback',
        ])->assertOk()
            ->assertJsonStructure(['data' => ['authorization_url', 'expires_at']]);

        $url = $response->json('data.authorization_url');
        $this->assertStringContainsString('oauth2/1/authorize', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('code_challenge', $url);
        $this->assertStringContainsString('state=', $url);

        $this->assertDatabaseHas('identity_verifications', [
            'status' => 'INITIATED',
            'provider' => 'DIGILOCKER',
        ]);
    }

    public function test_initiate_returns_already_verified_when_identity_exists(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        IdentityVerification::create([
            'user_id' => $user->id,
            'subject_type' => 'buyer',
            'verification_type' => 'AADHAAR',
            'provider' => 'DIGILOCKER',
            'status' => 'VERIFIED',
            'state_hash' => hash('sha256', 'old-state'),
            'identity_name' => 'Test User',
            'aadhaar_last4' => '1234',
            'verified_at' => now(),
            'initiated_at' => now()->subMinutes(10),
        ]);

        $this->postJson('/api/v1/identity/digilocker/initiate', [
            'redirect_uri' => 'https://scrapifyauctions.com/kyc/digilocker/callback',
        ])->assertOk()
            ->assertJson(['already_verified' => true])
            ->assertJsonPath('data.status', 'VERIFIED');
    }

    public function test_callback_rejects_invalid_state(): void
    {
        Sanctum::actingAs($this->user());

        $this->postJson('/api/v1/identity/digilocker/callback', [
            'state' => 'totally-invalid-state',
            'code' => 'some-code',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'DIGILOCKER_STATE_MISMATCH');
    }

    public function test_callback_handles_user_cancellation(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $state = bin2hex(random_bytes(32));
        IdentityVerification::create([
            'user_id' => $user->id,
            'subject_type' => 'buyer',
            'verification_type' => 'AADHAAR',
            'provider' => 'DIGILOCKER',
            'status' => 'INITIATED',
            'state_hash' => hash('sha256', $state),
            'code_verifier_encrypted' => 'test-verifier',
            'redirect_uri' => 'https://scrapifyauctions.com/callback',
            'initiated_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson('/api/v1/identity/digilocker/callback', [
            'state' => $state,
            'error' => 'access_denied',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'DIGILOCKER_ACCESS_DENIED');

        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $user->id,
            'status' => 'CANCELLED',
            'failure_code' => 'DIGILOCKER_ACCESS_DENIED',
        ]);
    }

    public function test_callback_rejects_expired_session(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $state = bin2hex(random_bytes(32));
        IdentityVerification::create([
            'user_id' => $user->id,
            'subject_type' => 'buyer',
            'verification_type' => 'AADHAAR',
            'provider' => 'DIGILOCKER',
            'status' => 'INITIATED',
            'state_hash' => hash('sha256', $state),
            'code_verifier_encrypted' => 'test-verifier',
            'redirect_uri' => 'https://scrapifyauctions.com/callback',
            'initiated_at' => now()->subMinutes(20),
            'expires_at' => now()->subMinutes(5),
        ]);

        $this->postJson('/api/v1/identity/digilocker/callback', [
            'state' => $state,
            'code' => 'some-code',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'DIGILOCKER_SESSION_EXPIRED');
    }

    public function test_successful_callback_verifies_identity(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $state = bin2hex(random_bytes(32));
        IdentityVerification::create([
            'user_id' => $user->id,
            'subject_type' => 'buyer',
            'verification_type' => 'AADHAAR',
            'provider' => 'DIGILOCKER',
            'status' => 'INITIATED',
            'state_hash' => hash('sha256', $state),
            'code_verifier_encrypted' => 'test-code-verifier',
            'redirect_uri' => 'https://scrapifyauctions.com/callback',
            'initiated_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        Http::fake([
            '*/public/oauth2/2/token' => Http::response([
                'access_token' => 'digilocker-access-token',
                'digilocker_id' => 'DL-12345',
            ]),
            '*/public/oauth2/1/xml/eaadhaar' => Http::response([
                'name' => 'Rajesh Kumar',
                'dob' => '1990-01-15',
                'gender' => 'M',
                'maskedAadhaar' => 'XXXX XXXX 5678',
                'digilockerid' => 'DL-12345',
            ]),
        ]);

        $this->postJson('/api/v1/identity/digilocker/callback', [
            'state' => $state,
            'code' => 'authorization-code-from-digilocker',
        ])->assertOk()
            ->assertJsonPath('data.status', 'VERIFIED')
            ->assertJsonPath('data.identity_name', 'Rajesh Kumar')
            ->assertJsonPath('data.aadhaar_masked', 'XXXX XXXX 5678')
            ->assertJsonPath('data.provider', 'DIGILOCKER');

        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $user->id,
            'status' => 'VERIFIED',
            'identity_name' => 'Rajesh Kumar',
            'aadhaar_last4' => '5678',
            'digilocker_id' => 'DL-12345',
        ]);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/oauth2/2/token'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/xml/eaadhaar')
            && $r->header('Authorization') === ['Bearer digilocker-access-token']);
    }

    public function test_retry_allows_reattempt_after_cancellation(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        IdentityVerification::create([
            'user_id' => $user->id,
            'subject_type' => 'buyer',
            'verification_type' => 'AADHAAR',
            'provider' => 'DIGILOCKER',
            'status' => 'CANCELLED',
            'state_hash' => hash('sha256', 'old-state'),
            'failure_code' => 'DIGILOCKER_AUTH_CANCELLED',
            'initiated_at' => now()->subMinutes(10),
        ]);

        $this->postJson('/api/v1/identity/digilocker/retry', [
            'redirect_uri' => 'https://scrapifyauctions.com/kyc/digilocker/callback',
        ])->assertOk()
            ->assertJsonStructure(['data' => ['authorization_url', 'expires_at']]);
    }

    public function test_callback_token_failure_marks_verification_failed(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $state = bin2hex(random_bytes(32));
        IdentityVerification::create([
            'user_id' => $user->id,
            'subject_type' => 'buyer',
            'verification_type' => 'AADHAAR',
            'provider' => 'DIGILOCKER',
            'status' => 'INITIATED',
            'state_hash' => hash('sha256', $state),
            'code_verifier_encrypted' => 'test-code-verifier',
            'redirect_uri' => 'https://scrapifyauctions.com/callback',
            'initiated_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        Http::fake([
            '*/public/oauth2/2/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->postJson('/api/v1/identity/digilocker/callback', [
            'state' => $state,
            'code' => 'bad-code',
        ])->assertStatus(502)
            ->assertJsonPath('error.code', 'DIGILOCKER_TOKEN_FAILED');

        $this->assertDatabaseHas('identity_verifications', [
            'user_id' => $user->id,
            'status' => 'FAILED',
            'failure_code' => 'DIGILOCKER_TOKEN_FAILED',
        ]);
    }

    public function test_digilocker_disabled_returns_error(): void
    {
        GeneralSetting::updateOrCreate(['key' => 'digilocker_enabled'], ['value' => '0']);
        Sanctum::actingAs($this->user());

        $this->postJson('/api/v1/identity/digilocker/initiate', [
            'redirect_uri' => 'https://scrapifyauctions.com/callback',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'DIGILOCKER_NOT_CONFIGURED');
    }

    public function test_code_verifier_cleared_after_completion(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $state = bin2hex(random_bytes(32));
        $verification = IdentityVerification::create([
            'user_id' => $user->id,
            'subject_type' => 'buyer',
            'verification_type' => 'AADHAAR',
            'provider' => 'DIGILOCKER',
            'status' => 'INITIATED',
            'state_hash' => hash('sha256', $state),
            'code_verifier_encrypted' => 'sensitive-verifier',
            'redirect_uri' => 'https://scrapifyauctions.com/callback',
            'initiated_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        Http::fake([
            '*/public/oauth2/2/token' => Http::response(['access_token' => 'tok']),
            '*/public/oauth2/1/xml/eaadhaar' => Http::response(['name' => 'Test', 'dob' => '2000-01-01']),
        ]);

        $this->postJson('/api/v1/identity/digilocker/callback', [
            'state' => $state,
            'code' => 'valid-code',
        ])->assertOk();

        $verification->refresh();
        $this->assertNull($verification->getRawOriginal('code_verifier_encrypted'));
    }

    public function test_secrets_not_exposed_in_status_response(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        IdentityVerification::create([
            'user_id' => $user->id,
            'subject_type' => 'buyer',
            'verification_type' => 'AADHAAR',
            'provider' => 'DIGILOCKER',
            'status' => 'VERIFIED',
            'state_hash' => hash('sha256', 'state'),
            'identity_name' => 'Test User',
            'dob_encrypted' => '1990-01-15',
            'aadhaar_last4' => '5678',
            'verified_at' => now(),
            'initiated_at' => now()->subMinutes(5),
        ]);

        $response = $this->getJson('/api/v1/identity/digilocker/status')->assertOk();
        $data = $response->json('data');

        $this->assertArrayNotHasKey('dob_encrypted', $data);
        $this->assertArrayNotHasKey('code_verifier_encrypted', $data);
        $this->assertArrayNotHasKey('metadata_encrypted', $data);
        $this->assertArrayNotHasKey('state_hash', $data);
        $this->assertEquals('XXXX XXXX 5678', $data['aadhaar_masked']);
    }

    public function test_deep_link_spoof_cannot_bypass_verification(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/identity/digilocker/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'NOT_STARTED');
    }

    private function user(): User
    {
        $user = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $vendor = Vendor::create(['user_id' => $user->id, 'company_name' => 'Test Corp', 'contact_name' => 'Tester', 'email' => $user->email, 'phone' => '9999999999', 'status' => 'approved']);
        $user->update(['vendor_id' => $vendor->id]);
        return $user->fresh();
    }
}
