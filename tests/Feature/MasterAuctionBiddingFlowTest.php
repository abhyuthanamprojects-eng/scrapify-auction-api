<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\Category;
use App\Models\EmdTransaction;
use App\Models\Lot;
use App\Models\ProxyBid;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MasterAuctionBiddingFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $sellerUser;
    private Vendor $sellerVendor;
    private User $buyerUser1;
    private Vendor $buyerVendor1;
    private User $buyerUser2;
    private Vendor $buyerVendor2;
    private User $adminUser;
    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create([
            'name' => 'Ferrous Scrap',
            'slug' => 'ferrous-scrap',
            'is_active' => true,
        ]);

        // Seller
        $this->sellerUser = User::factory()->create([
            'email' => 'seller.master@scrapify.com',
            'role' => 'seller',
            'status' => 'active',
        ]);
        $this->sellerVendor = Vendor::create([
            'user_id' => $this->sellerUser->id,
            'company_name' => 'Apex Metals Disposal Ltd',
            'contact_name' => 'Rajesh Sharma',
            'email' => 'seller.master@scrapify.com',
            'phone' => '+919810011111',
            'status' => 'approved',
        ]);
        $this->sellerUser->update(['vendor_id' => $this->sellerVendor->id]);

        // Buyer 1
        $this->buyerUser1 = User::factory()->create([
            'email' => 'buyer1.master@scrapify.com',
            'role' => 'buyer',
            'status' => 'active',
        ]);
        $this->buyerVendor1 = Vendor::create([
            'user_id' => $this->buyerUser1->id,
            'company_name' => 'Bharat Smelters Corp',
            'contact_name' => 'Amit Verma',
            'email' => 'buyer1.master@scrapify.com',
            'phone' => '+919820022222',
            'status' => 'approved',
        ]);
        $this->buyerUser1->update(['vendor_id' => $this->buyerVendor1->id]);
        // Fund buyer 1 wallet
        $w1 = app(WalletService::class)->forUser($this->buyerUser1);
        app(WalletService::class)->credit($w1, 'add_money', 100000.0, ['note' => 'Test funding']);

        // Buyer 2
        $this->buyerUser2 = User::factory()->create([
            'email' => 'buyer2.master@scrapify.com',
            'role' => 'buyer',
            'status' => 'active',
        ]);
        $this->buyerVendor2 = Vendor::create([
            'user_id' => $this->buyerUser2->id,
            'company_name' => 'Zenith Recyclers Pvt Ltd',
            'contact_name' => 'Pooja Iyer',
            'email' => 'buyer2.master@scrapify.com',
            'phone' => '+919830033333',
            'status' => 'approved',
        ]);
        $this->buyerUser2->update(['vendor_id' => $this->buyerVendor2->id]);
        // Fund buyer 2 wallet
        $w2 = app(WalletService::class)->forUser($this->buyerUser2);
        app(WalletService::class)->credit($w2, 'add_money', 100000.0, ['note' => 'Test funding']);

        // Admin User
        $this->adminUser = User::factory()->create([
            'email' => 'ops.master@scrapify.com',
            'role' => 'operations',
            'status' => 'active',
        ]);
    }

    public function test_role_enforcement_on_auction_creation(): void
    {
        // 1. Buyer attempts to create Forward Auction -> 403 Blocked
        Sanctum::actingAs($this->buyerUser1);
        $res = $this->postJson('/api/v1/auctions', [
            'title' => 'Unauthorized Forward Auction',
            'company' => 'Bharat Smelters Corp',
            'direction' => 'forward',
            'starting_price' => 50000,
            'bid_increment' => 1000,
        ]);
        $res->assertStatus(403);

        // 2. Seller attempts to create Reverse Auction -> 403 Blocked
        Sanctum::actingAs($this->sellerUser);
        $res = $this->postJson('/api/v1/auctions', [
            'title' => 'Unauthorized Reverse Auction',
            'company' => 'Apex Metals Disposal Ltd',
            'direction' => 'reverse',
            'starting_price' => 45000,
            'bid_increment' => 500,
        ]);
        $res->assertStatus(403);

        // 3. Seller creates Forward Auction -> 201 Created
        Sanctum::actingAs($this->sellerUser);
        $res = $this->postJson('/api/v1/auctions', [
            'title' => 'Heavy MS Scrap Disposal Q3',
            'company' => 'Apex Metals Disposal Ltd',
            'direction' => 'forward',
            'starting_price' => 50000,
            'bid_increment' => 1000,
            'reserve_price' => 60000,
            'emd_amount' => 5000,
            'status' => 'draft',
        ]);
        $res->assertStatus(201);

        // 4. Buyer creates Reverse Auction -> 201 Created
        Sanctum::actingAs($this->buyerUser1);
        $res = $this->postJson('/api/v1/auctions', [
            'title' => 'Procurement Tender for Copper Wire',
            'company' => 'Bharat Smelters Corp',
            'direction' => 'reverse',
            'starting_price' => 45000,
            'bid_increment' => 500,
            'reserve_price' => 38000,
            'emd_amount' => 5000,
            'status' => 'draft',
        ]);
        $res->assertStatus(201);
    }

    public function test_complete_forward_auction_bidding_lifecycle(): void
    {
        // 1. Create and publish Forward Auction
        Sanctum::actingAs($this->sellerUser);
        $createRes = $this->postJson('/api/v1/auctions', [
            'title' => 'Industrial Cable Scrap Lot',
            'company' => 'Apex Metals Disposal Ltd',
            'direction' => 'forward',
            'starting_price' => 50000,
            'bid_increment' => 1000,
            'reserve_price' => 52000,
            'emd_amount' => 5000,
            'status' => 'draft',
            'schedule_start' => now()->subHour(),
            'schedule_end' => now()->addHours(2),
        ]);
        $createRes->assertStatus(201);
        $code = $createRes->json('data.code');

        // Submit for approval
        $this->postJson("/api/v1/auctions/{$code}/submit")->assertStatus(200);

        // Admin approves and publishes
        Sanctum::actingAs($this->adminUser);
        $this->postJson("/api/v1/auctions/{$code}/approve")->assertStatus(200);
        $this->postJson("/api/v1/auctions/{$code}/publish")->assertStatus(200);

        // Make auction live
        $auction = Auction::where('code', $code)->firstOrFail();
        $auction->update(['status' => 'live']);

        // 2. Self-bidding prevention: Seller attempts to bid on own auction -> 422
        Sanctum::actingAs($this->sellerUser);
        $selfRes = $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 50000]);
        $selfRes->assertStatus(422);

        // 3. First Bid Validation: Buyer bids below starting price -> 422
        Sanctum::actingAs($this->buyerUser1);
        $lowRes = $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 48000]);
        $lowRes->assertStatus(422);

        // 4. Valid First Bid: Buyer 1 bids exact starting price -> 201
        $bid1 = $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 50000]);
        $bid1->assertStatus(201)
            ->assertJsonPath('auction.current_highest_inr', 50000);

        // Verify EMD locked for Buyer 1
        $emd1 = EmdTransaction::where('auction_id', $auction->id)->where('vendor_id', $this->buyerVendor1->id)->first();
        $this->assertNotNull($emd1);
        $this->assertEquals('locked', $emd1->status);
        $this->assertEquals(5000, (float) $emd1->amount);

        // 5. Subsequent Bid Validation: Buyer 2 bids less than (H1 + increment) -> 422
        Sanctum::actingAs($this->buyerUser2);
        $invalidStep = $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 50500]);
        $invalidStep->assertStatus(422);

        // 6. Valid Subsequent Bid: Buyer 2 bids 51,000 -> 201 (H1 = 51,000)
        $bid2 = $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 51000]);
        $bid2->assertStatus(201)
            ->assertJsonPath('auction.current_highest_inr', 51000);

        // 7. Proxy Bidding: Buyer 1 configures auto-bid max 54,000
        Sanctum::actingAs($this->buyerUser1);
        $this->postJson("/api/v1/auctions/{$code}/proxy-bid", ['max_amount' => 54000])
            ->assertStatus(201);

        // Buyer 2 bids manually 52,000 -> Proxy automatically reacts and counters with 53,000!
        Sanctum::actingAs($this->buyerUser2);
        $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 52000])
            ->assertStatus(201);

        $auction->refresh();
        $this->assertEquals(53000, (float) $auction->current_highest);

        // 8. Close Auction & Settlement (53,000 >= 52,000 reserve price -> Winner awarded!)
        Sanctum::actingAs($this->adminUser);
        $closeRes = $this->postJson("/api/v1/auctions/{$code}/close");
        $closeRes->assertStatus(200);

        $auction->refresh();
        $this->assertEquals('closed', $auction->status);
        $this->assertEquals($this->buyerVendor1->id, $auction->winner_vendor_id);
        $this->assertEquals(53000, (float) $auction->final_price);

        // Verify loser EMD is released and winner EMD remains locked
        $emdBuyer2 = EmdTransaction::where('auction_id', $auction->id)->where('vendor_id', $this->buyerVendor2->id)->first();
        $this->assertEquals('released', $emdBuyer2->status);

        $emdWinner = EmdTransaction::where('auction_id', $auction->id)->where('vendor_id', $this->buyerVendor1->id)->first();
        $this->assertEquals('locked', $emdWinner->status);
    }

    public function test_forward_auction_reserve_not_met(): void
    {
        Sanctum::actingAs($this->sellerUser);
        $createRes = $this->postJson('/api/v1/auctions', [
            'title' => 'High Reserve Machinery Scrap',
            'company' => 'Apex Metals Disposal Ltd',
            'direction' => 'forward',
            'starting_price' => 50000,
            'bid_increment' => 1000,
            'reserve_price' => 90000, // High reserve
            'emd_amount' => 5000,
            'status' => 'draft',
        ]);
        $code = $createRes->json('data.code');
        $auction = Auction::where('code', $code)->firstOrFail();
        $auction->update(['status' => 'live']);

        // Buyer 1 bids 50,000
        Sanctum::actingAs($this->buyerUser1);
        $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 50000])->assertStatus(201);

        // Close auction
        Sanctum::actingAs($this->adminUser);
        $this->postJson("/api/v1/auctions/{$code}/close")->assertStatus(200);

        $auction->refresh();
        $this->assertEquals('closed', $auction->status);
        $this->assertNull($auction->winner_vendor_id);
        $this->assertEquals('Reserve price not met', $auction->review_comment);
    }

    public function test_complete_reverse_auction_tender_lifecycle(): void
    {
        // 1. Buyer creates Reverse Procurement Tender
        Sanctum::actingAs($this->buyerUser1);
        $res = $this->postJson('/api/v1/auctions', [
            'title' => 'Tender for 100 MT Aluminum Ingots',
            'company' => 'Bharat Smelters Corp',
            'direction' => 'reverse',
            'starting_price' => 45000,
            'bid_increment' => 500, // decrement
            'reserve_price' => 40000, // floor price
            'emd_amount' => 3000,
            'status' => 'draft',
            'schedule_start' => now()->subHour(),
            'schedule_end' => now()->addHours(2),
        ]);
        $res->assertStatus(201);
        $code = $res->json('data.code');

        // Submit & Admin Approve
        $this->postJson("/api/v1/auctions/{$code}/submit")->assertStatus(200);
        Sanctum::actingAs($this->adminUser);
        $this->postJson("/api/v1/auctions/{$code}/approve")->assertStatus(200);
        $this->postJson("/api/v1/auctions/{$code}/publish")->assertStatus(200);

        $auction = Auction::where('code', $code)->firstOrFail();
        $auction->update(['status' => 'live']);

        // Fund seller wallet for EMD
        $ws = app(WalletService::class)->forUser($this->sellerUser);
        app(WalletService::class)->credit($ws, 'add_money', 50000.0, ['note' => 'Seller test funding']);

        // 2. Buyer cannot bid on own reverse tender -> 422
        Sanctum::actingAs($this->buyerUser1);
        $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 44000])->assertStatus(422);

        // 3. First Quote Validation: Quote exceeding starting ceiling -> 422
        Sanctum::actingAs($this->sellerUser);
        $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 46000])->assertStatus(422);

        // 4. Valid First Quote: 45,000 -> 201 (L1 = 45,000)
        $q1 = $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 45000]);
        $q1->assertStatus(201);
        $auction->refresh();
        $this->assertEquals(45000, (float) $auction->current_highest);

        // 5. Decrement Validation: Quote of 44,800 does not meet decrement of 500 (max allowed is 44,500) -> 422
        $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 44800])->assertStatus(422);

        // 6. Valid Lower Quote: 44,500 -> 201 (L1 = 44,500)
        $q2 = $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 44500]);
        $q2->assertStatus(201);
        $auction->refresh();
        $this->assertEquals(44500, (float) $auction->current_highest);

        // 7. Floor Validation: Quote below reserve floor (40,000) -> 422
        $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 38000])->assertStatus(422);

        // 8. Close Auction & Settle L1 Winner
        Sanctum::actingAs($this->adminUser);
        $this->postJson("/api/v1/auctions/{$code}/close")->assertStatus(200);

        $auction->refresh();
        $this->assertEquals('closed', $auction->status);
        $this->assertEquals($this->sellerVendor->id, $auction->winner_vendor_id);
        $this->assertEquals(44500, (float) $auction->final_price);
    }

    public function test_multi_lot_auction_independent_bidding_and_winners(): void
    {
        Sanctum::actingAs($this->sellerUser);
        $res = $this->postJson('/api/v1/auctions', [
            'title' => 'Multi-Lot Scrap Metals Package',
            'company' => 'Apex Metals Disposal Ltd',
            'direction' => 'forward',
            'lot_type' => 'lot_wise',
            'starting_price' => 20000,
            'bid_increment' => 500,
            'emd_amount' => 2000,
            'status' => 'draft',
            'sub_lots' => [
                ['name' => 'Lot 1: Copper Wire', 'quantity' => '10', 'uom' => 'MT', 'reserve_price' => 20000],
                ['name' => 'Lot 2: Aluminum Sheets', 'quantity' => '15', 'uom' => 'MT', 'reserve_price' => 30000],
            ],
        ]);
        $res->assertStatus(201);
        $code = $res->json('data.code');

        $auction = Auction::where('code', $code)->with('lots')->firstOrFail();
        $auction->update(['status' => 'live']);

        $lot1 = $auction->lots[0];
        $lot2 = $auction->lots[1];

        // Buyer 1 bids on Lot 1 (Copper)
        Sanctum::actingAs($this->buyerUser1);
        $b1Res = $this->postJson("/api/v1/auctions/{$code}/bids", ['lot' => $lot1->code, 'amount' => 20000]);
        $b1Res->assertStatus(201);

        // Buyer 2 bids on Lot 2 (Aluminum)
        Sanctum::actingAs($this->buyerUser2);
        $b2Res = $this->postJson("/api/v1/auctions/{$code}/bids", ['lot' => $lot2->code, 'amount' => 30000]);
        $b2Res->assertStatus(201);

        // Close auction
        Sanctum::actingAs($this->adminUser);
        $this->postJson("/api/v1/auctions/{$code}/close")->assertStatus(200);

        $lot1->refresh();
        $lot2->refresh();

        $this->assertEquals($this->buyerVendor1->id, $lot1->winner_vendor_id);
        $this->assertEquals(20000, (float) $lot1->final_price);

        $this->assertEquals($this->buyerVendor2->id, $lot2->winner_vendor_id);
        $this->assertEquals(30000, (float) $lot2->final_price);
    }

    public function test_anti_sniping_auto_extension(): void
    {
        Sanctum::actingAs($this->sellerUser);
        $res = $this->postJson('/api/v1/auctions', [
            'title' => 'Expiring Live Auction',
            'company' => 'Apex Metals Disposal Ltd',
            'direction' => 'forward',
            'starting_price' => 10000,
            'bid_increment' => 500,
            'status' => 'draft',
            'schedule_start' => now()->subHour(),
            'schedule_end' => now()->addMinutes(2), // 2 mins remaining (within 3 mins window)
        ]);
        $code = $res->json('data.code');
        $auction = Auction::where('code', $code)->firstOrFail();
        $auction->update(['status' => 'live']);

        $originalEnd = $auction->schedule_end;

        // Buyer places bid in final 2 minutes
        Sanctum::actingAs($this->buyerUser1);
        $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 10000])->assertStatus(201);

        $auction->refresh();
        // Schedule end must be extended by 3 minutes
        $this->assertTrue($auction->schedule_end->greaterThan($originalEnd));
    }

    public function test_insufficient_wallet_balance_blocks_emd_and_bid(): void
    {
        // Create user with 0 wallet balance
        $poorUser = User::factory()->create([
            'email' => 'poor.buyer@scrapify.com',
            'role' => 'buyer',
            'status' => 'active',
        ]);
        $poorVendor = Vendor::create([
            'user_id' => $poorUser->id,
            'company_name' => 'Zero Balance Metals',
            'contact_name' => 'Sanjay Gupta',
            'email' => 'poor.buyer@scrapify.com',
            'phone' => '+919999999999',
            'status' => 'approved',
        ]);
        $poorUser->update(['vendor_id' => $poorVendor->id]);

        Sanctum::actingAs($this->sellerUser);
        $res = $this->postJson('/api/v1/auctions', [
            'title' => 'High EMD Auction',
            'company' => 'Apex Metals Disposal Ltd',
            'direction' => 'forward',
            'starting_price' => 50000,
            'bid_increment' => 1000,
            'emd_amount' => 50000, // Requires 50,000 EMD
            'status' => 'draft',
        ]);
        $code = $res->json('data.code');
        $auction = Auction::where('code', $code)->firstOrFail();
        $auction->update(['status' => 'live']);

        // Poor user attempts to bid -> 422 Insufficient Balance
        Sanctum::actingAs($poorUser);
        $res = $this->postJson("/api/v1/auctions/{$code}/bids", ['amount' => 50000]);
        $res->assertStatus(422);
    }
}
