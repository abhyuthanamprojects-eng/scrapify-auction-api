<?php

namespace Tests\Feature;

use App\Events\BidPlaced;
use App\Models\Auction;
use App\Models\AuctionResult;
use App\Models\AuctionSlot;
use App\Models\AuctionConfigSnapshot;
use App\Models\AuctionTermsAcceptance;
use App\Models\AuctionTermsVersion;
use App\Models\Award;
use App\Models\Bid;
use App\Models\EmdTransaction;
use App\Models\SettlementLedgerEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WinnerConfirmation;
use App\Models\Notification;
use App\Services\WalletService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LiveAuctionEngineTest extends TestCase
{
    use RefreshDatabase;

    private function bidder(string $email): array
    {
        $user = User::factory()->create(['email' => $email, 'role' => 'buyer', 'status' => 'active']);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'company_name' => 'Bidder '.$email,
            'contact_name' => 'Test Bidder',
            'email' => $email,
            'phone' => '9999999999',
            'status' => 'approved',
        ]);
        $user->update(['vendor_id' => $vendor->id]);
        $wallet = app(WalletService::class)->forUser($user);
        app(WalletService::class)->credit($wallet, 'add_money', 100000.0, ['note' => 'live engine test']);

        return [$user->fresh(), $vendor->fresh()];
    }

    private function liveAuction(string $direction = 'forward', float $increment = 1000): array
    {
        [$user, $vendor] = $this->bidder('bidder-'.uniqid().'@example.com');
        $started = now()->subMinute();
        $auction = Auction::create([
            'title' => 'Live engine test',
            'company' => 'Scrapify',
            'status' => 'live',
            'direction' => $direction,
            'starting_price' => 50000,
            'bid_increment' => $increment,
            'emd_amount' => 5000,
            'actual_started_at' => $started,
            'schedule_end' => $started->copy()->addHours(2),
        ]);
        $slot = AuctionSlot::create([
            'auction_id' => $auction->id,
            'sequence' => 1,
            'type' => 'initial',
            'starts_at' => $started,
            'ends_at' => now()->addMinutes(20),
            'cutoff_at' => now()->addMinutes(20)->subMilliseconds(500),
            'status' => 'active',
        ]);

        return [$auction->fresh(), $slot->fresh(), $user, $vendor];
    }

    public function test_same_idempotency_key_returns_one_bid_and_keeps_slot_linkage(): void
    {
        [$auction, $slot, $user] = $this->liveAuction();
        Sanctum::actingAs($user);

        $payload = ['amount' => 50000, 'idempotency_key' => 'client-bid-001'];
        $this->postJson("/api/v1/auctions/{$auction->code}/bids", $payload)->assertCreated();
        $this->postJson("/api/v1/auctions/{$auction->code}/bids", $payload)->assertCreated();

        $this->assertSame(1, Bid::where('auction_id', $auction->id)->count());
        $this->assertSame($slot->id, Bid::where('auction_id', $auction->id)->value('slot_id'));
    }

    public function test_bid_at_or_after_server_cutoff_is_rejected_without_extending_slot(): void
    {
        [$auction, $slot, $user] = $this->liveAuction();
        $originalEnd = $slot->ends_at->copy();
        $slot->update(['cutoff_at' => now()->subMilliseconds(1)]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/auctions/{$auction->code}/bids", [
            'amount' => 50000,
            'idempotency_key' => 'late-bid-001',
        ])->assertStatus(422);

        $this->assertSame(0, Bid::where('auction_id', $auction->id)->count());
        $this->assertTrue($slot->fresh()->ends_at->equalTo($originalEnd));
    }

    public function test_equal_amounts_use_earlier_server_bid_as_deterministic_leader(): void
    {
        [$auction, $slot, $firstUser] = $this->liveAuction('forward', 0);
        [, $secondVendor] = $this->bidder('second-'.uniqid().'@example.com');
        $secondUser = $secondVendor->user;

        app(\App\Services\BiddingService::class)->place($auction, $firstUser->vendor, $firstUser, 50000, null, null, false, 'tie-1');
        app(\App\Services\BiddingService::class)->place($auction, $secondVendor, $secondUser, 50000, null, null, false, 'tie-2');

        $this->assertSame($firstUser->vendor_id, Bid::where('auction_id', $auction->id)->orderBy('created_at')->orderBy('id')->value('vendor_id'));
        $this->assertSame(50000.0, (float) $auction->fresh()->current_highest);
    }

    public function test_authoritative_start_rechecks_readiness_and_is_idempotent(): void
    {
        [$participant, $vendor] = $this->bidder('start-'.uniqid().'@example.com');
        $admin = User::factory()->create(['role' => 'operations', 'status' => 'active']);
        $auction = Auction::create([
            'title' => 'Start test', 'company' => 'Scrapify', 'status' => 'published',
            'direction' => 'forward', 'starting_price' => 50000, 'bid_increment' => 1000,
            'schedule_start' => now()->subMinute(), 'schedule_end' => now()->addHours(3),
        ]);
        $snapshot = AuctionConfigSnapshot::create([
            'auction_id' => $auction->id, 'version' => 1, 'frozen_by' => $admin->id,
            'frozen_at' => now(), 'config' => [
                'minimum_participants' => 1, 'initial_slot_minutes' => 30,
                'continuation_slot_minutes' => 2, 'bid_cutoff_ms' => 500,
                'maximum_auction_duration_minutes' => 120, 'rfq_required' => false,
                'emd_required' => false, 'direction' => 'forward', 'bid_increment' => 1000,
            ],
        ]);
        $terms = AuctionTermsVersion::create([
            'auction_id' => $auction->id, 'version' => 1, 'published_by' => $admin->id,
            'terms_text' => 'Live engine test terms', 'rules' => ['config_snapshot_id' => $snapshot->id], 'published_at' => now(),
        ]);
        $auction->update(['config_snapshot_id' => $snapshot->id, 'current_terms_version_id' => $terms->id]);
        AuctionTermsAcceptance::create(['auction_id' => $auction->id, 'user_id' => $participant->id, 'terms_version_id' => $terms->id, 'accepted_at' => now()]);
        $wallet = app(WalletService::class)->forUser($participant);
        EmdTransaction::create(['auction_id' => $auction->id, 'vendor_id' => $vendor->id, 'wallet_id' => $wallet->id, 'amount' => 0, 'required_amount' => 0, 'paid_amount' => 0, 'verified_amount' => 0, 'status' => 'locked', 'locked_at' => now()]);

        Sanctum::actingAs($admin);
        $first = $this->postJson("/api/v1/auctions/{$auction->code}/go-live")->assertOk();
        $second = $this->postJson("/api/v1/auctions/{$auction->code}/go-live")->assertOk();

        $this->assertNotNull($first->json('data.actual_started_at'));
        $this->assertSame($first->json('data.actual_started_at'), $second->json('data.actual_started_at'));
        $this->assertSame(1, AuctionSlot::where('auction_id', $auction->id)->count());
        $this->assertSame('live', $auction->fresh()->status);
    }

    public function test_live_state_exposes_server_clock_slot_and_ranked_values(): void
    {
        [$auction, $slot, $user] = $this->liveAuction();
        Sanctum::actingAs($user);
        $this->postJson("/api/v1/auctions/{$auction->code}/bids", [
            'amount' => 50000,
            'idempotency_key' => 'state-bid-001',
        ])->assertCreated();

        $this->getJson("/api/v1/auctions/{$auction->code}/live-state")
            ->assertOk()
            ->assertJsonPath('status', 'live')
            ->assertJsonPath('active_slot.id', $slot->id)
            ->assertJsonPath('active_slot.cutoff_at', $slot->fresh()->cutoff_at->toIso8601String())
            ->assertJsonPath('bid_count', 1)
            ->assertJsonPath('ranking.0.rank', 'H1')
            ->assertJsonPath('own_rank', 1)
            ->assertJsonStructure(['server_time', 'hard_end_at', 'last_bid', 'ranking']);

        $publicEvent = (new BidPlaced(Bid::latest('id')->firstOrFail()->load(['auction', 'lot'])))->broadcastWith();
        $this->assertArrayNotHasKey('vendor_id', $publicEvent['bid']);
        $this->assertArrayNotHasKey('vendor_name', $publicEvent['bid']);
    }

    public function test_auditor_can_read_live_state_but_cannot_mutate_live_engine(): void
    {
        [$auction, $slot] = $this->liveAuction();
        $auditor = User::factory()->create(['role' => 'auditor', 'status' => 'active']);
        Sanctum::actingAs($auditor);

        $this->getJson("/api/v1/auctions/{$auction->code}/live-state")
            ->assertOk()
            ->assertJsonPath('auction_id', $auction->id)
            ->assertJsonStructure(['slots', 'participants', 'audit_events']);

        $this->postJson("/api/v1/auctions/{$auction->code}/go-live")->assertForbidden();
        $this->postJson("/api/v1/auctions/{$auction->code}/slots/{$slot->sequence}/close", ['reason' => 'audit'])->assertForbidden();
        $this->postJson("/api/v1/auctions/{$auction->code}/slots/next")->assertForbidden();
        $this->postJson("/api/v1/auctions/{$auction->code}/close", ['reason' => 'audit'])->assertForbidden();
    }

    public function test_authoritative_close_freezes_result_and_retains_top_two_emd(): void
    {
        [$auction, , $firstUser] = $this->liveAuction();
        [, $secondVendor] = $this->bidder('result-second-'.uniqid().'@example.com');
        $secondUser = $secondVendor->user;
        $admin = User::factory()->create(['role' => 'operations', 'status' => 'active']);

        Sanctum::actingAs($firstUser);
        $this->postJson("/api/v1/auctions/{$auction->code}/bids", ['amount' => 50000, 'idempotency_key' => 'result-1'])->assertCreated();
        Sanctum::actingAs($secondUser);
        $this->postJson("/api/v1/auctions/{$auction->code}/bids", ['amount' => 51000, 'idempotency_key' => 'result-2'])->assertCreated();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/auctions/{$auction->code}/close")->assertOk();
        $result = AuctionResult::where('auction_id', $auction->id)->firstOrFail();

        $this->assertSame($secondVendor->id, $result->winner_vendor_id);
        $this->assertSame($firstUser->vendor_id, $result->second_rank_vendor_id);
        $this->assertSame('H1', $result->ranking_snapshot[0]['rank']);
        $this->assertSame('H2', $result->ranking_snapshot[1]['rank']);
        $this->assertSame(2, SettlementLedgerEntry::where('result_id', $result->id)->count());
        $this->assertSame('locked', EmdTransaction::where('auction_id', $auction->id)->where('vendor_id', $secondVendor->id)->value('status'));
        $this->assertSame('locked', EmdTransaction::where('auction_id', $auction->id)->where('vendor_id', $firstUser->vendor_id)->value('status'));
        $this->getJson("/api/v1/auctions/{$auction->code}/result")->assertOk()->assertJsonPath('data.id', $result->id);

        $award = Award::where('result_id', $result->id)->firstOrFail();
        Sanctum::actingAs($secondUser);
        $this->postJson("/api/v1/awards/{$award->id}/decline", ['reason' => 'Unable to proceed'])->assertOk();
        Sanctum::actingAs($admin);
        $fallbackResponse = $this->postJson("/api/v1/awards/{$award->id}/default", ['reason' => 'Winner declined', 'forfeit_emd' => false])->assertOk()->assertJsonPath('data.fallback_offer.rank', 'H2');
        $this->assertSame('DEFAULTED', WinnerConfirmation::where('result_id', $result->id)->where('rank', 'H1')->value('confirmation_status'));
        $this->assertSame('CONFIRMATION_PENDING', WinnerConfirmation::where('result_id', $result->id)->where('rank', 'H2')->value('confirmation_status'));
        Sanctum::actingAs($firstUser);
        $this->postJson('/api/v1/fallback-offers/'.$fallbackResponse->json('data.fallback_offer.id').'/decline', ['reason' => 'Fallback not viable'])->assertOk();
        $this->assertSame('FALLBACK_EXHAUSTED_REVIEW_REQUIRED', $result->fresh()->status);
    }

    public function test_loss_adjustment_is_capped_and_refund_completion_is_idempotent(): void
    {
        [$auction, , $firstUser] = $this->liveAuction();
        [, $secondVendor] = $this->bidder('settlement-second-'.uniqid().'@example.com');
        $admin = User::factory()->create(['role' => 'operations', 'status' => 'active']);
        app(\App\Services\BiddingService::class)->place($auction, $firstUser->vendor, $firstUser, 50000, null, null, false, 'settle-1');
        app(\App\Services\BiddingService::class)->place($auction, $secondVendor, $secondVendor->user, 51000, null, null, false, 'settle-2');
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/auctions/{$auction->code}/close")->assertOk();
        $result = AuctionResult::where('auction_id', $auction->id)->firstOrFail();
        $entry = SettlementLedgerEntry::where('result_id', $result->id)->where('operation_type', 'EMD_RETAIN_PRIMARY')->firstOrFail();
        $entry->update(['proposed_amount' => 1000]);
        $adjustment = app(\App\Services\SettlementService::class)->applyLossAdjustment($entry->fresh(), 'Actual commercial loss', 'ACTUAL_LOSS_ONLY', $admin->id);
        $this->assertSame(100000, $adjustment['applied_cents']);
        $emd = EmdTransaction::findOrFail($entry->emd_id);
        $this->assertSame('refund_pending', $emd->status);
        $this->assertSame('1000.00', (string) $emd->forfeited_amount);
        $refund = SettlementLedgerEntry::where('result_id', $result->id)->where('operation_type', 'EMD_REFUND_QUEUED')->latest('id')->firstOrFail();
        $service = app(\App\Services\SettlementService::class);
        $service->startRefund($refund, $admin->id);
        $service->completeRefund($refund, 'MANUAL-REF-1', $admin->id);
        $service->completeRefund($refund, 'MANUAL-REF-1', $admin->id);
        $emd = $emd->fresh();
        $this->assertSame('refunded', $emd->status);
        $this->assertSame(500000, (int) round(((float) $emd->refunded_amount + (float) $emd->forfeited_amount) * 100));
        $this->assertSame(1, SettlementLedgerEntry::where('id', $refund->id)->where('status', 'completed')->count());
    }

    public function test_forward_and_reverse_result_ranking_and_top_two_retention_are_direction_aware(): void
    {
        foreach ([
            ['forward', [550000, 590000, 580000], ['H1', 'H2', 'H3']],
            ['reverse', [47000, 42000, 43000], ['L1', 'L2', 'L3']],
        ] as [$direction, $amounts, $ranks]) {
            [$auction, , $firstUser] = $this->liveAuction($direction, 0);
            [, $secondVendor] = $this->bidder('rank-second-'.uniqid().'@example.com');
            [, $thirdVendor] = $this->bidder('rank-third-'.uniqid().'@example.com');
            $vendors = [$firstUser->vendor, $secondVendor, $thirdVendor];
            foreach ($vendors as $i => $vendor) {
                app(\App\Services\EmdService::class)->ensureLocked($auction, $vendor);
                Bid::create(['auction_id' => $auction->id, 'vendor_id' => $vendor->id, 'user_id' => $vendor->user_id, 'vendor_name' => $vendor->company_name, 'amount' => $amounts[$i], 'idempotency_key' => "rank-{$direction}-{$i}-".uniqid()]);
            }
            $auction->update(['status' => 'closed', 'closed_at' => now()]);
            $result = app(\App\Services\AuctionResultService::class)->finalize($auction->fresh());
            $this->assertSame($ranks, array_column($result->ranking_snapshot, 'rank'));
            $expected = $direction === 'forward' ? [590000, 580000, 550000] : [42000, 43000, 47000];
            $this->assertSame($expected, array_map(fn ($row) => (int) $row['amount'], $result->ranking_snapshot));
            $this->assertSame(2, EmdTransaction::where('auction_id', $auction->id)->where('status', 'locked')->count());
            $this->assertSame(1, EmdTransaction::where('auction_id', $auction->id)->where('status', 'refund_pending')->count());
        }
    }

    public function test_server_command_expires_overdue_winner_confirmation(): void
    {
        [$auction, , $firstUser] = $this->liveAuction();
        [, $secondVendor] = $this->bidder('expiry-second-'.uniqid().'@example.com');
        $admin = User::factory()->create(['role' => 'operations', 'status' => 'active']);
        app(\App\Services\BiddingService::class)->place($auction, $firstUser->vendor, $firstUser, 50000, null, null, false, 'expiry-1');
        app(\App\Services\BiddingService::class)->place($auction, $secondVendor, $secondVendor->user, 51000, null, null, false, 'expiry-2');
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/auctions/{$auction->code}/close")->assertOk();
        $confirmation = WinnerConfirmation::where('auction_id', $auction->id)->where('rank', 'H1')->firstOrFail();
        $confirmation->update(['response_deadline' => now()->subMinute()]);
        Artisan::call('auctions:expire-winner-confirmations');
        $this->assertSame('REVIEW_REQUIRED', $confirmation->fresh()->confirmation_status);
        $this->assertSame('defaulted', Award::where('result_id', $confirmation->result_id)->value('status'));
    }

    public function test_notification_business_key_is_idempotent_and_audit_carries_request_id(): void
    {
        $user = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $service = app(NotificationService::class);
        $service->push($user, 'REFUND_COMPLETED', 'Refund completed', 'Done', ['ledger_id' => 44], 'ledger:44:refund-completed:REF-1');
        $service->push($user, 'REFUND_COMPLETED', 'Refund completed', 'Done', ['ledger_id' => 44], 'ledger:44:refund-completed:REF-1');

        $this->assertSame(1, Notification::where('business_key', 'ledger:44:refund-completed:REF-1')->count());
    }

    public function test_seller_can_view_only_own_auction_result_and_never_emd_details(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        [, $winner] = $this->bidder('seller-result-winner-'.uniqid().'@example.com');
        [, $runnerUp] = $this->bidder('seller-result-runner-up-'.uniqid().'@example.com');
        $auction = Auction::create(['title' => 'Seller result', 'company' => 'Scrapify', 'status' => 'closed', 'direction' => 'forward', 'submitted_by' => $seller->id, 'closed_at' => now()]);
        AuctionResult::create(['auction_id' => $auction->id, 'auction_type' => 'forward', 'status' => 'settled', 'closed_at' => now(), 'final_value' => 51000, 'winner_vendor_id' => $winner->id, 'second_rank_vendor_id' => $runnerUp->id, 'ranking_snapshot' => [['rank' => 'H1', 'amount' => '51000', 'vendor_id' => $winner->id], ['rank' => 'H2', 'amount' => '50000', 'vendor_id' => $runnerUp->id]]]);

        Sanctum::actingAs($seller);
        $this->getJson("/api/v1/auctions/{$auction->code}/result")->assertOk()->assertJsonPath('data.seller.ranking.0.rank', 'H1')->assertJsonPath('data.emd', null);

        $otherSeller = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        Sanctum::actingAs($otherSeller);
        $this->getJson("/api/v1/auctions/{$auction->code}/result")->assertForbidden();
    }

    public function test_buyer_cannot_view_another_participants_result_or_emd(): void
    {
        [$buyerA, $vendorA] = $this->bidder('buyer-a-isolation-'.uniqid().'@example.com');
        [, $vendorB] = $this->bidder('buyer-b-isolation-'.uniqid().'@example.com');
        $auction = Auction::create([
            'title' => 'Buyer isolation',
            'company' => 'Scrapify',
            'status' => 'closed',
            'direction' => 'forward',
            'closed_at' => now(),
        ]);
        $result = AuctionResult::create([
            'auction_id' => $auction->id,
            'auction_type' => 'forward',
            'status' => 'settled',
            'closed_at' => now(),
            'final_value' => 51000,
            'winner_vendor_id' => $vendorB->id,
            'ranking_snapshot' => [['rank' => 'H1', 'amount' => '51000', 'vendor_id' => $vendorB->id]],
        ]);
        EmdTransaction::create([
            'auction_id' => $auction->id,
            'vendor_id' => $vendorA->id,
            'wallet_id' => $buyerA->wallet->id,
            'amount' => 1000,
            'status' => 'locked',
        ]);
        $buyerB = $vendorB->user()->firstOrFail();
        EmdTransaction::create([
            'auction_id' => $auction->id,
            'vendor_id' => $vendorB->id,
            'wallet_id' => $buyerB->wallet->id,
            'amount' => 2000,
            'status' => 'locked',
        ]);

        Sanctum::actingAs($buyerA);

        $this->getJson("/api/v1/auctions/{$auction->code}/result")
            ->assertForbidden();

        $this->getJson('/api/v1/emd')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount_inr', 1000);

        $this->assertNotNull($result->fresh());
    }
}
