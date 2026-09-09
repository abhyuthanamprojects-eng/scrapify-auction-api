<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationContextSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_credentials_are_rejected_by_public_login_without_a_token(): void
    {
        $admin = User::factory()->create([
            'email' => 'operator@scrapify.test',
            'role' => 'operations',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'ADMIN_LOGIN_NOT_ALLOWED_HERE')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_public_credentials_are_rejected_by_admin_login_without_a_token(): void
    {
        $buyer = User::factory()->create([
            'email' => 'buyer@scrapify.test',
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $buyer->email,
            'password' => 'password',
        ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'ADMIN_ROLE_REQUIRED')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_and_public_tokens_are_bound_to_their_own_auth_context(): void
    {
        $buyer = User::factory()->create([
            'email' => 'buyer-context@scrapify.test',
            'role' => 'buyer',
            'status' => 'active',
        ]);
        $admin = User::factory()->create([
            'email' => 'admin-context@scrapify.test',
            'role' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($buyer);
        $this->getJson('/api/v1/admin/auth/me')->assertForbidden()
            ->assertJsonPath('error.code', 'ADMIN_ROLE_REQUIRED');

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/auth/me')->assertForbidden()
            ->assertJsonPath('error.code', 'PUBLIC_ROLE_REQUIRED');
    }

    public function test_public_tokens_cannot_call_admin_kyb_or_platform_configuration_endpoints(): void
    {
        foreach (['buyer', 'seller'] as $role) {
            $user = User::factory()->create([
                'role' => $role,
                'status' => 'active',
            ]);

            Sanctum::actingAs($user);

            $this->getJson('/api/v1/admin/kyb')
                ->assertForbidden()
                ->assertJsonPath('error.code', 'ADMIN_ROLE_REQUIRED');

            $this->patchJson('/api/v1/platform-config', [])
                ->assertForbidden()
                ->assertJsonPath('error.code', 'ADMIN_ROLE_REQUIRED');
        }
    }

    public function test_successful_logins_issue_distinct_context_tokens(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'operations', 'status' => 'active']);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $buyer->email,
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['token', 'user']);

        $this->postJson('/api/v1/admin/auth/login', [
            'identifier' => $admin->email,
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['token', 'user']);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $buyer->id,
            'name' => 'public-web',
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $admin->id,
            'name' => 'admin-panel',
        ]);
    }

    public function test_public_login_rejects_a_mismatched_buyer_or_seller_context(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $response = $this->postJson('/api/v1/auth/login', [
            'identifier' => $buyer->email,
            'password' => 'password',
            'login_context' => 'seller',
        ]);
        $response->assertForbidden()->assertJsonPath('error.code', 'ROLE_CONTEXT_MISMATCH');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_registration_persists_the_selected_public_role_and_rejects_admin(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'QA Seller', 'email' => 'qa-seller@example.com', 'phone' => '9000000001',
            'password' => 'StrongPass_1234', 'registration_type' => 'SELLER',
        ])->assertCreated()->assertJsonPath('user.role', 'seller')->assertJsonPath('user.status', 'active');
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Invalid Admin', 'email' => 'qa-admin@example.com', 'phone' => '9000000002',
            'password' => 'StrongPass_1234', 'registration_type' => 'admin',
        ])->assertUnprocessable();
    }
}
