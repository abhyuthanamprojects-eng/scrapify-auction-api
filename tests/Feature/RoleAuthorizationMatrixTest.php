<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\AuctionResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleAuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_cannot_create_an_auction(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);

        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/auctions', [
            'title' => 'Buyer must not create this',
            'company' => 'Buyer company',
        ])->assertForbidden();
    }

    public function test_seller_cannot_place_a_bid_even_on_a_live_auction(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        $auction = Auction::create([
            'title' => 'Seller-owned auction',
            'company' => 'Seller company',
            'status' => 'live',
            'direction' => 'forward',
            'submitted_by' => $seller->id,
        ]);

        Sanctum::actingAs($seller);

        $this->postJson("/api/v1/auctions/{$auction->code}/bids", [
            'amount' => 1000,
        ])->assertForbidden();
    }

    public function test_seller_cannot_update_another_sellers_configuration(): void
    {
        $owner = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        $otherSeller = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        $auction = Auction::create([
            'title' => 'Owner auction',
            'company' => 'Owner company',
            'status' => 'draft',
            'direction' => 'forward',
            'submitted_by' => $owner->id,
        ]);

        Sanctum::actingAs($otherSeller);

        $this->patchJson("/api/v1/auctions/{$auction->code}/configuration", [
            'initial_slot_minutes' => 45,
        ])->assertForbidden();
    }

    public function test_seller_cannot_manage_another_sellers_lots(): void
    {
        $owner = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        $otherSeller = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        $auction = Auction::create([
            'title' => 'Owner lot auction',
            'company' => 'Owner company',
            'status' => 'draft',
            'direction' => 'forward',
            'lot_type' => 'lot_wise',
            'submitted_by' => $owner->id,
        ]);

        Sanctum::actingAs($otherSeller);

        $this->postJson("/api/v1/auctions/{$auction->code}/lots", [
            'name' => 'Unauthorized lot',
        ])->assertForbidden();
    }

    public function test_buyer_cannot_trigger_an_admin_approval_workflow(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $auction = Auction::create([
            'title' => 'Buyer approval test',
            'company' => 'Buyer company',
            'status' => 'submitted',
            'direction' => 'forward',
            'submitted_by' => $buyer->id,
        ]);

        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/auctions/{$auction->code}/approvals", [
            'tier' => 'L1',
            'trigger_reason' => 'Buyer must not create an approval request',
        ])->assertForbidden();
    }

    public function test_seller_cannot_delete_an_auction(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        $auction = Auction::create([
            'title' => 'Delete protection test',
            'company' => 'Seller company',
            'status' => 'draft',
            'direction' => 'forward',
            'submitted_by' => $seller->id,
        ]);

        Sanctum::actingAs($seller);

        $this->deleteJson("/api/v1/auctions/{$auction->code}")
            ->assertStatus(405);
        $this->assertDatabaseHas('auctions', ['id' => $auction->id]);
    }

    public function test_public_users_cannot_call_admin_auction_archive(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        $auction = Auction::create([
            'title' => 'Admin archive access test',
            'company' => 'Seller company',
            'status' => 'draft',
            'direction' => 'forward',
            'submitted_by' => $seller->id,
        ]);

        foreach ([$buyer, $seller] as $user) {
            Sanctum::actingAs($user);
            $this->deleteJson("/api/v1/admin/auctions/{$auction->code}", [
                'reason' => 'Unauthorized archive attempt',
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('auctions', ['id' => $auction->id, 'status' => 'draft']);
    }

    public function test_admin_can_archive_a_draft_with_reason_and_audit(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $auction = Auction::create([
            'title' => 'Admin archive draft',
            'company' => 'Seller company',
            'status' => 'draft',
            'direction' => 'forward',
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/admin/auctions/{$auction->code}", [
            'reason' => 'Duplicate draft created during controlled testing.',
        ])->assertOk()
            ->assertJsonPath('data.archived', true)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('auctions', ['id' => $auction->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'AUCTION_ARCHIVED_BY_ADMIN',
            'entity_id' => $auction->code,
        ]);
    }

    public function test_admin_archive_requires_reason_and_cannot_archive_live_or_historical_auctions(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        foreach (['live', 'closed'] as $status) {
            $auction = Auction::create([
                'title' => "Protected {$status} auction",
                'company' => 'Seller company',
                'status' => $status,
                'direction' => 'forward',
            ]);

            $this->deleteJson("/api/v1/admin/auctions/{$auction->code}", [
                'reason' => 'Attempted protected archive',
            ])->assertStatus(422);
            $this->assertDatabaseHas('auctions', ['id' => $auction->id, 'status' => $status]);
        }

        $draft = Auction::create([
            'title' => 'Reason required archive',
            'company' => 'Seller company',
            'status' => 'draft',
            'direction' => 'forward',
        ]);
        $this->deleteJson("/api/v1/admin/auctions/{$draft->code}")
            ->assertUnprocessable();
    }

    public function test_admin_cannot_archive_an_auction_with_result_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $auction = Auction::create([
            'title' => 'Historical result archive test',
            'company' => 'Seller company',
            'status' => 'published',
            'direction' => 'forward',
        ]);
        AuctionResult::create([
            'auction_id' => $auction->id,
            'auction_type' => 'forward',
            'status' => 'settled',
            'closed_at' => now(),
            'final_value' => 1000,
            'ranking_snapshot' => [],
        ]);

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/admin/auctions/{$auction->code}", [
            'reason' => 'Historical record must remain retained.',
        ])->assertStatus(422);

        $this->assertDatabaseHas('auctions', ['id' => $auction->id, 'status' => 'published']);
        $this->assertDatabaseHas('auction_results', ['auction_id' => $auction->id]);
    }
}
