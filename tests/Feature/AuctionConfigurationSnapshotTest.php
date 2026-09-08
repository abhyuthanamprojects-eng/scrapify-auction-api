<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\AuctionConfigSnapshot;
use App\Models\AuctionTermsVersion;
use App\Models\GeneralSetting;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AuctionEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuctionConfigurationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_auction_configuration_is_validated_and_frozen_at_publish(): void
    {
        $admin = User::factory()->create(['role' => 'operations', 'status' => 'active']);
        $auction = Auction::create([
            'title' => 'Configurable auction', 'company' => 'Scrapify', 'status' => 'approved',
            'direction' => 'forward', 'bid_increment' => 1000, 'starting_price' => 50000,
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/auctions/{$auction->code}/configuration", [
            'rfq_mode' => 'HYBRID', 'emd_type' => 'FIXED', 'emd_fixed_amount' => 15000,
            'minimum_participants' => 2, 'initial_slot_minutes' => 25, 'bid_cutoff_ms' => 500,
        ])->assertOk();

        $this->postJson("/api/v1/auctions/{$auction->code}/publish")->assertOk();
        $auction->refresh();
        $snapshot = AuctionConfigSnapshot::findOrFail($auction->config_snapshot_id);
        $terms = AuctionTermsVersion::where('auction_id', $auction->id)->firstOrFail();

        $this->assertSame('HYBRID', $snapshot->config['rfq_mode']);
        $this->assertSame(15000.0, (float) $snapshot->config['emd_fixed_amount']);
        $this->assertSame($snapshot->id, $terms->rules['config_snapshot_id']);

        GeneralSetting::updateOrCreate(['key' => 'initial_slot_minutes'], ['value' => '90']);
        $this->assertSame(25, (int) $snapshot->fresh()->config['initial_slot_minutes']);
    }

    public function test_invalid_auction_configuration_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'operations', 'status' => 'active']);
        $auction = Auction::create(['title' => 'Invalid config', 'company' => 'Scrapify', 'status' => 'draft']);
        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/auctions/{$auction->code}/configuration", [
            'rfq_mode' => 'INVALID', 'bid_cutoff_ms' => 999999,
        ])->assertStatus(422);
    }

    public function test_readiness_reports_missing_locked_participants(): void
    {
        $auction = Auction::create(['title' => 'Readiness', 'company' => 'Scrapify', 'status' => 'published']);
        $this->getJson("/api/v1/auctions/{$auction->code}/readiness")
            ->assertOk()->assertJsonPath('data.ready', false)->assertJsonPath('data.eligible_participants', 0);
    }

    public function test_eligibility_returns_actionable_reason_codes(): void
    {
        $user = User::factory()->create(['role'=>'buyer','status'=>'active']);
        $vendor = Vendor::create(['user_id'=>$user->id,'company_name'=>'Test Vendor','contact_name'=>'Test User','email'=>'test@example.com','phone'=>'9999999999','status'=>'approved']);
        $auction = Auction::create(['title'=>'Eligibility','company'=>'Scrapify','status'=>'published']);
        $result = app(AuctionEligibilityService::class)->evaluate($auction, $vendor);
        $this->assertFalse($result['eligible']);
        $this->assertContains('EMD_NOT_SUBMITTED', $result['reasons']);
        $this->assertContains('TNC_VERSION_OUTDATED', $result['reasons']);
    }
}
