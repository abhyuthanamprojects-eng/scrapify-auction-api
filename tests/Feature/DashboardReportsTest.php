<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_finance_and_customer_totals_without_any_auctions(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        User::factory()->create(['role' => 'seller', 'status' => 'suspended']);
        Wallet::where('user_id', $buyer->id)->update(['balance' => 50000, 'locked' => 12500]);
        Sanctum::actingAs($admin, ['admin:panel']);

        $response = $this->getJson('/api/v1/reports/dashboard')->assertOk()
            ->assertJsonPath('kpis.total_customers', 2)
            ->assertJsonPath('kpis.active_customers', 1)
            ->assertJsonPath('kpis.emd_held', 12500)
            ->assertJsonPath('kpis.live_auctions', 0)
            ->assertJsonPath('kpis.refunds_due', 0)
            ->assertJsonPath('kpis.settlement_due', 0)
            ->assertJsonCount(12, 'monthly_volume')
            ->assertJsonCount(0, 'auction_type_mix');

        $months = array_column($response->json('monthly_volume'), 'month');
        $this->assertSame(now()->startOfMonth()->subMonths(11)->format('M Y'), $months[0]);
        $this->assertSame(now()->format('M Y'), $months[11]);
    }

    public function test_public_buyer_cannot_read_platform_dashboard(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'buyer']), ['public:web']);
        $this->getJson('/api/v1/reports/dashboard')->assertForbidden();
    }
}
