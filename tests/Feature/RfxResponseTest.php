<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\RfxPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RfxResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_participating_vendor_can_submit_an_rfx_response(): void
    {
        $user = User::whereNotNull('vendor_id')->firstOrFail();
        $auction = Auction::firstOrFail();
        $package = $auction->rfxPackages()->firstOrFail();

        $response = $this->actingAs($user)->postJson(
            "/api/v1/auctions/{$auction->code}/rfx/{$package->id}/submit",
            ['answers' => ['q1' => 'updated permit', 'q2' => '1800']],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'submitted');
        $this->assertDatabaseHas('rfx_responses', [
            'rfx_package_id' => $package->id,
            'vendor_id' => $user->vendor_id,
            'status' => 'submitted',
        ]);
    }

    public function test_closed_rfx_package_rejects_submissions(): void
    {
        $user = User::whereNotNull('vendor_id')->firstOrFail();
        $auction = Auction::firstOrFail();
        $package = $auction->rfxPackages()->firstOrFail();
        $package->update(['status' => 'closed']);

        $this->actingAs($user)
            ->postJson("/api/v1/auctions/{$auction->code}/rfx/{$package->id}/submit", ['answers' => ['q1' => 'x']])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This RFx package is not open for submissions.');
    }
}
