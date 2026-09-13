<?php

namespace Tests\Feature;

use App\Models\Otp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_otp_uses_sms_provider_and_never_returns_a_debug_code(): void
    {
        config([
            'services.msg91.auth_key' => 'test-auth-key',
            'services.msg91.otp_template_id' => 'test-template',
            'services.msg91.sender_id' => 'TESTBR',
            'services.msg91.country_code' => '91',
        ]);
        Http::fake([
            'https://control.msg91.com/*' => Http::response(['type' => 'success'], 200),
        ]);

        $response = $this->postJson('/api/v1/auth/request-otp', [
            'identifier' => '9876543210',
            'purpose' => 'register',
        ]);

        $response->assertOk()
            ->assertJsonPath('channel', 'sms')
            ->assertJsonMissingPath('debug_code')
            ->assertJsonMissingPath('otp');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/otp?') && $request->hasHeader('authkey', 'test-auth-key'));
    }

    public function test_email_request_uses_configured_mailer_without_returning_the_code(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/request-otp', [
            'identifier' => 'person@example.com',
            'purpose' => 'register',
        ]);

        $response->assertOk()
            ->assertJsonPath('channel', 'email')
            ->assertJsonMissingPath('debug_code')
            ->assertJsonMissingPath('otp');
    }

    public function test_registration_requires_recent_mobile_and_email_verification(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Unverified User',
            'email' => 'unverified@example.com',
            'phone' => '9876543210',
            'password' => 'StrongPass_1234',
            'role' => 'buyer',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrorFor('email');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_vendor_registration_rejects_invalid_identity_and_location_formats(): void
    {
        $user = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        Sanctum::actingAs($user, ['public:web']);

        $response = $this->postJson('/api/v1/vendors/register', [
            'company_name' => 'Invalid Format Co',
            'contact_name' => 'Test Contact',
            'email' => 'not-an-email',
            'phone' => '12345',
            'gst_number' => 'INVALID-GSTIN',
            'pan_number' => 'INVALID-PAN',
            'warehouse_details' => [
                'name' => 'Main Yard',
                'address' => 'Industrial Area',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '00012',
                'contact_phone' => '12345',
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
                'phone',
                'gst_number',
                'pan_number',
                'warehouse_details.pincode',
                'warehouse_details.contact_phone',
            ]);
    }

    public function test_vendor_registration_rejects_city_and_state_that_do_not_match_the_pin_service(): void
    {
        Http::fake([
            'https://api.postalpincode.in/pincode/400030' => Http::response([[
                'Status' => 'Success',
                'PostOffice' => [[
                    'District' => 'Mumbai',
                    'State' => 'Maharashtra',
                    'Country' => 'India',
                ]],
            ]], 200),
        ]);

        $user = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        Sanctum::actingAs($user, ['public:web']);

        $response = $this->postJson('/api/v1/vendors/register', [
            'company_name' => 'Location Check Co',
            'contact_name' => 'Test Contact',
            'email' => 'location@example.com',
            'phone' => '9876543210',
            'pincode' => '400030',
            'city' => 'Jaipur',
            'state' => 'Rajasthan',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['city', 'state']);
    }

    public function test_registration_otp_purpose_cannot_be_replayed_as_login(): void
    {
        config([
            'services.msg91.auth_key' => 'test-auth-key',
            'services.msg91.otp_template_id' => 'test-template',
            'services.msg91.sender_id' => 'TESTBR',
            'services.msg91.country_code' => '91',
        ]);
        Http::fake([
            'https://control.msg91.com/api/v5/otp/verify*' => Http::response(['type' => 'success'], 200),
        ]);
        Otp::create([
            'identifier' => '9876543210',
            'channel' => 'sms',
            'purpose' => 'register',
            'code' => 'managed',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->postJson('/api/v1/auth/verify-otp', [
            'identifier' => '9876543210',
            'code' => '123456',
            'purpose' => 'login',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/auth/verify-otp', [
            'identifier' => '9876543210',
            'code' => '123456',
            'purpose' => 'register',
        ])->assertOk()
            ->assertJson(['verified' => true, 'token' => null]);
    }

    public function test_admin_otp_settings_mask_and_encrypt_the_msg91_auth_key(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin, ['admin:panel']);

        $response = $this->putJson('/api/v1/admin/otp-settings', [
            'msg91_enabled' => true,
            'msg91_auth_key' => 'secret-auth-key',
            'msg91_otp_template_id' => 'template-123',
            'msg91_sender_id' => 'TESTBR',
            'msg91_country_code' => '91',
        ]);

        $response->assertOk()
            ->assertJsonPath('msg91_auth_key', 'se***********ey')
            ->assertJsonMissing(['msg91_auth_key' => 'secret-auth-key']);

        $stored = (string) \App\Models\GeneralSetting::where('key', 'msg91_auth_key')->value('value');
        $this->assertNotSame('secret-auth-key', $stored);
        $this->assertSame('secret-auth-key', Crypt::decryptString($stored));
    }

    public function test_admin_can_send_a_test_email_otp_without_exposing_the_code(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin, ['admin:panel']);

        $response = $this->postJson('/api/v1/admin/otp-settings/test-email', [
            'email' => 'smtp-test@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('destination', 'smtp-test@example.com')
            ->assertJsonMissingPath('debug_code')
            ->assertJsonMissingPath('otp');
    }
}
