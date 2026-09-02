<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\GatePass;
use App\Models\InspectionBooking;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class FullPlatformEndToEndFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * Complete Test: Buyer & Seller Onboarding, Document OCR, and Admin Verification
     */
    public function test_complete_buyer_and_seller_registration_and_admin_verification_flow(): void
    {
        // 1. Buyer Registration
        $buyerRegResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Rahul Singhania',
            'email' => 'rahul@apexinfra.com',
            'phone' => '+91 9988771122',
            'password' => 'Password@1234',
            'role' => 'buyer',
            'company_name' => 'Apex Infrastructure Corp',
        ]);

        $buyerRegResponse->assertStatus(201)
            ->assertJsonPath('user.email', 'rahul@apexinfra.com')
            ->assertJsonPath('user.vendor.status', 'pending');

        $buyerToken = $buyerRegResponse->json('token');
        $buyerVendorCode = $buyerRegResponse->json('user.vendor.code') ?? $buyerRegResponse->json('user.vendor.id');
        $this->assertNotEmpty($buyerToken);
        $this->assertNotEmpty($buyerVendorCode);

        // 2. Buyer Profile KYC submission
        $buyerProfileResponse = $this->withHeader('Authorization', "Bearer {$buyerToken}")
            ->postJson('/api/v1/vendors/register', [
                'company_name' => 'Apex Infrastructure Corp',
                'contact_name' => 'Rahul Singhania',
                'email' => 'rahul@apexinfra.com',
                'phone' => '+91 9988771122',
                'gst_number' => '27AAACA1234A1Z5',
                'pan_number' => 'AAACA1234A',
                'address' => 'Plot 42, MIDC Industrial Area, Pune, Maharashtra 411018',
                'terms_accepted' => true,
            ]);

        $buyerProfileResponse->assertStatus(201)
            ->assertJsonPath('data.gst_number', '27AAACA1234A1Z5');

        // 3. Buyer Uploads GST Certificate with OCR Extraction
        $fakePdf = UploadedFile::fake()->create('gst_certificate.pdf', 300, 'application/pdf');
        $buyerDocResponse = $this->withHeader('Authorization', "Bearer {$buyerToken}")
            ->postJson("/api/v1/vendors/{$buyerVendorCode}/documents", [
                'doc_key' => 'gst',
                'kind' => 'GST Certificate',
                'file' => $fakePdf,
            ]);

        $buyerDocResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('ocr.status', 'processed')
            ->assertJsonPath('ocr.confidence', 98.80);

        $docId = $buyerDocResponse->json('document.id');

        // 4. Seller Registration
        $sellerRegResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Tata Recycling Head',
            'email' => 'head@tatarecycling.com',
            'phone' => '+91 9988772233',
            'password' => 'Password@1234',
            'role' => 'seller',
            'company_name' => 'Tata Steel Heavy Recycling Yard',
        ]);

        $sellerRegResponse->assertStatus(201)
            ->assertJsonPath('user.role', 'seller');

        $sellerVendorCode = $sellerRegResponse->json('user.vendor.code') ?? $sellerRegResponse->json('user.vendor.id');

        // 5. Admin Authentication & Verification
        $adminUser = User::where('role', 'super_admin')->first();
        $this->actingAs($adminUser);

        // Admin fetches pending vendors list
        $pendingVendorsResponse = $this->getJson('/api/v1/vendors?status=pending');
        $pendingVendorsResponse->assertStatus(200);

        // Admin reviews Buyer document
        $docReviewResponse = $this->patchJson("/api/v1/vendors/{$buyerVendorCode}/documents/{$docId}", [
            'status' => 'approved',
        ]);
        $docReviewResponse->assertStatus(200)
            ->assertJsonPath('document.status', 'approved');

        // Admin approves Buyer Profile
        $buyerApproveResponse = $this->postJson("/api/v1/vendors/{$buyerVendorCode}/approve");
        $buyerApproveResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        // Admin approves Seller Profile
        $sellerApproveResponse = $this->postJson("/api/v1/vendors/{$sellerVendorCode}/approve");
        $sellerApproveResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        // Verify in database that both vendors are now active and approved
        $this->assertDatabaseHas('vendors', ['code' => $buyerVendorCode, 'status' => 'approved']);
        $this->assertDatabaseHas('vendors', ['code' => $sellerVendorCode, 'status' => 'approved']);
    }

    /**
     * Complete Test: Forward and Reverse Auction Creation, Multi-Level Review, and Admin Publishing
     */
    public function test_complete_forward_and_reverse_auction_creation_review_and_publishing_flow(): void
    {
        $adminUser = User::where('role', 'super_admin')->first();
        $this->actingAs($adminUser);

        // 1. Seller creates Forward Auction (Selling Heavy Metal Scrap)
        $fwdResponse = $this->postJson('/api/v1/auctions', [
            'title' => 'Surplus Industrial Copper Scrap & Armoured High Voltage Cables',
            'company' => 'Tata Power Jamshedpur Works',
            'direction' => 'forward',
            'lot_type' => 'single',
            'reserve_price' => 5200000.00,
            'starting_price' => 4500000.00,
            'bid_increment' => 50000.00,
            'emd_amount' => 250000.00,
            'schedule_start' => now()->addDays(1)->toISOString(),
            'schedule_end' => now()->addDays(4)->toISOString(),
            'sub_lots' => [
                [
                    'title' => 'Heavy Copper Armoured Cable 99.5% Purity',
                    'quantity' => '120 MT',
                    'unit' => 'MT',
                    'starting_price' => 4500000.00,
                    'reserve_price' => 5200000.00,
                    'bid_increment' => 50000.00,
                ],
            ],
        ]);

        $fwdResponse->assertStatus(201);
        $fwdCode = $fwdResponse->json('data.code');
        $this->assertNotEmpty($fwdCode);

        // Submit Forward Auction for Review
        $fwdSubmit = $this->postJson("/api/v1/auctions/{$fwdCode}/submit");
        $fwdSubmit->assertStatus(200)
            ->assertJsonPath('data.status', 'pending_approval');

        // Admin Approves Forward Auction
        $fwdApprove = $this->postJson("/api/v1/auctions/{$fwdCode}/approve");
        $fwdApprove->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        // Admin Publishes Forward Auction
        $fwdPublish = $this->postJson("/api/v1/auctions/{$fwdCode}/publish");
        $fwdPublish->assertStatus(200)
            ->assertJsonPath('data.status', 'published');

        // 2. Enterprise Procurement creates Reverse Auction (Sourcing Logistics & Freight)
        $revResponse = $this->postJson('/api/v1/auctions', [
            'title' => 'Pan-India Heavy Freight & Flatbed Transport Contract RFP',
            'company' => 'Scrapify Logistics Division',
            'direction' => 'reverse',
            'lot_type' => 'single',
            'reserve_price' => 1800000.00,
            'starting_price' => 2200000.00,
            'bid_increment' => 20000.00,
            'emd_amount' => 100000.00,
            'schedule_start' => now()->addDays(1)->toISOString(),
            'schedule_end' => now()->addDays(3)->toISOString(),
        ]);

        $revResponse->assertStatus(201);
        $revCode = $revResponse->json('data.code');

        // Submit & Approve Reverse Auction
        $this->postJson("/api/v1/auctions/{$revCode}/submit")->assertStatus(200);
        $this->postJson("/api/v1/auctions/{$revCode}/approve")->assertStatus(200);
        $this->postJson("/api/v1/auctions/{$revCode}/publish")->assertStatus(200)
            ->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('auctions', ['code' => $fwdCode, 'status' => 'published', 'direction' => 'forward']);
        $this->assertDatabaseHas('auctions', ['code' => $revCode, 'status' => 'published', 'direction' => 'reverse']);
    }

    /**
     * Complete Test: Pre-Auction Warehouse Inspection, Gate Pass QR Generation, Gate Scan & Live Bidding
     */
    public function test_complete_pre_auction_warehouse_inspection_gate_pass_qr_and_bidding_flow(): void
    {
        $buyerUser = User::whereNotNull('vendor_id')->first();
        $buyerVendor = $buyerUser->vendor;
        $buyerVendor->update(['status' => 'approved']);

        $auction = Auction::first();
        $auction->update([
            'status' => 'live',
            'schedule_start' => now()->subHour(),
            'schedule_end' => now()->addDays(2),
        ]);

        // 1. Buyer books site inspection to check the project/lot at warehouse
        $this->actingAs($buyerUser);
        $bookingResponse = $this->postJson("/api/v1/auctions/{$auction->code}/inspections", [
            'visitor_name' => 'Rajesh Sharma (Senior QC Engineer)',
            'visitor_mobile' => '+91 98765 43210',
            'visitor_govt_id' => 'PAN: ABCDE1234F',
            'vehicle_number' => 'MH-12-PQ-9988',
            'slot_date' => now()->addDay()->toDateString(),
            'slot_time' => '11:00 AM - 12:30 PM',
        ]);

        $bookingResponse->assertStatus(201)
            ->assertJsonPath('success', true);

        $qrToken = $bookingResponse->json('data.gate_pass.qr_token');
        $this->assertNotEmpty($qrToken);

        // 2. Security Guard Scans QR at Warehouse Gate
        $verifyResponse = $this->getJson("/api/v1/gate-passes/verify/{$qrToken}");
        $verifyResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.booking.visitor_name', 'Rajesh Sharma (Senior QC Engineer)')
            ->assertJsonPath('data.booking.vehicle_number', 'MH-12-PQ-9988');

        // 3. Security Guard admits visitor at gate
        $scanResponse = $this->postJson("/api/v1/gate-passes/{$qrToken}/scan");
        $scanResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'used');

        // Verify booking marked as attended
        $this->assertDatabaseHas('gate_passes', ['qr_token' => $qrToken, 'status' => 'used']);
        $this->assertDatabaseHas('inspection_bookings', ['auction_id' => $auction->id, 'status' => 'attended']);

        // 4. Buyer locks EMD and places live bid
        $wallet = app(\App\Services\WalletService::class)->forUser($buyerUser);
        $wallet->update(['balance' => 500000.00]);

        $bidAmount = (float) $auction->starting_price + (float) $auction->bid_increment;
        $lotCode = $auction->isLotWise() ? $auction->lots()->first()?->code : null;
        $bidPayload = ['amount' => $bidAmount];
        if ($lotCode) {
            $bidPayload['lot'] = $lotCode;
        }

        $bidResponse = $this->postJson("/api/v1/auctions/{$auction->code}/bids", $bidPayload);

        $bidResponse->assertStatus(201);
        $this->assertEquals($bidAmount, (float) $bidResponse->json('bid.amount'));

        $this->assertDatabaseHas('bids', [
            'auction_id' => $auction->id,
            'vendor_id' => $buyerVendor->id,
            'amount' => $bidAmount,
        ]);
    }
}
