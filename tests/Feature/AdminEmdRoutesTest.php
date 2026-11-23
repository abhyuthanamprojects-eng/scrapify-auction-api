<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminEmdRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_emd_through_admin_route(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'status' => 'active']), ['admin:panel']);

        $this->getJson('/api/v1/admin/emd')->assertOk();
    }

    public function test_public_buyer_cannot_use_admin_emd_routes(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'buyer', 'status' => 'active']), ['public:web']);

        $this->getJson('/api/v1/admin/emd')->assertForbidden();
        $this->postJson('/api/v1/admin/emd/1/release')->assertForbidden();
    }

    public function test_forfeit_requires_reason(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'status' => 'active']), ['admin:panel']);

        $this->postJson('/api/v1/emd/999999/forfeit')->assertStatus(422)->assertJsonValidationErrors('reason');
    }
}
