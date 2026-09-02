<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\GatePass;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnterpriseAuctionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_categories_and_auctions_public_listing(): void
    {
        $response = $this->getJson('/api/v1/categories');
        $response->assertStatus(200)
            ->assertJsonPath('data.0.name', fn ($name) => ! empty($name));

        $auctionsResponse = $this->getJson('/api/v1/auctions');
        $auctionsResponse->assertStatus(200);
    }

    public function test_inspection_booking_and_gate_pass_qr_verification(): void
    {
        $vendorUser = User::whereNotNull('vendor_id')->first();
        $auction = Auction::first();

        $this->actingAs($vendorUser);

        // Book inspection
        $response = $this->postJson("/api/v1/auctions/{$auction->code}/inspections", [
            'visitor_name' => 'Amit Sinha',
            'visitor_mobile' => '+91 9988776655',
            'visitor_govt_id' => 'PAN: ABCDE1234F',
            'slot_date' => now()->addDay()->toDateString(),
            'slot_time' => '10:00 AM - 11:30 AM',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $qrToken = $response->json('data.gate_pass.qr_token');
        $this->assertNotEmpty($qrToken);

        // Public gate pass verify
        $verifyResponse = $this->getJson("/api/v1/gate-passes/verify/{$qrToken}");
        $verifyResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'active');
    }

    public function test_clarification_and_addendum_workflow(): void
    {
        $vendorUser = User::whereNotNull('vendor_id')->first();
        $adminUser = User::where('role', 'super_admin')->first();
        $auction = Auction::first();

        // Vendor asks question
        $this->actingAs($vendorUser);
        $qResponse = $this->postJson("/api/v1/auctions/{$auction->code}/clarifications", [
            'question' => 'Is there crane support available for heavy coil loading?',
            'section' => 'Loading & Dispatch',
            'is_public' => true,
        ]);
        $qResponse->assertStatus(201);
        $questionId = $qResponse->json('data.id');

        // Admin answers question
        $this->actingAs($adminUser);
        $aResponse = $this->postJson("/api/v1/auctions/{$auction->code}/clarifications/{$questionId}/answer", [
            'answer' => 'Yes, 50 MT overhead gantry crane is operational at Bay 4.',
            'is_public' => true,
        ]);
        $aResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'answered');

        // Admin publishes Addendum
        $addendumResponse = $this->postJson("/api/v1/auctions/{$auction->code}/addenda", [
            'title' => 'Bay 4 Crane Operation Safety Directive',
            'description' => 'Mandatory PPE for all heavy trailer drivers.',
        ]);
        $addendumResponse->assertStatus(201)
            ->assertJsonPath('data.addendum_number', 'Addendum-02');
    }

    public function test_dispute_registration_and_arbitration(): void
    {
        $vendorUser = User::whereNotNull('vendor_id')->first();
        $adminUser = User::where('role', 'super_admin')->first();
        $auction = Auction::first();

        $this->actingAs($vendorUser);
        $dispResponse = $this->postJson('/api/v1/disputes', [
            'auction_id' => $auction->id,
            'category' => 'Quality Mismatch',
            'severity' => 'High',
            'title' => 'Copper recovery test variance exceeds ±1.5% limit',
            'description' => 'Assay report showed lower purity than listed spec.',
            'claim_amount' => 250000.00,
        ]);

        $dispResponse->assertStatus(201)
            ->assertJsonPath('data.status', 'new');
        $dispCode = $dispResponse->json('data.code');

        // Admin resolves dispute
        $this->actingAs($adminUser);
        $resolveResponse = $this->postJson("/api/v1/disputes/{$dispCode}/resolve", [
            'resolution_summary' => 'Credit note approved for ₹250,000 against assay test variance.',
            'status' => 'resolved',
        ]);
        $resolveResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'resolved');
    }

    public function test_document_upload_and_ocr_extraction(): void
    {
        $vendorUser = User::whereNotNull('vendor_id')->first();
        $vendor = $vendorUser->vendor;

        $this->actingAs($vendorUser);

        $fakeFile = \Illuminate\Http\UploadedFile::fake()->create('gstin_certificate.pdf', 250, 'application/pdf');

        $response = $this->postJson("/api/v1/vendors/{$vendor->code}/documents", [
            'doc_key' => 'gst',
            'kind' => 'GST Certificate',
            'file' => $fakeFile,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('ocr.status', 'pending')
            ->assertJsonMissingPath('ocr.confidence')
            ->assertJsonMissingPath('ocr.extracted_data');
    }

    public function test_auction_creation_and_approval_verification(): void
    {
        $adminUser = User::where('role', 'super_admin')->first();
        $this->actingAs($adminUser);

        // 1. Create Auction Draft
        $createResponse = $this->postJson('/api/v1/auctions', [
            'title' => 'Surplus Industrial CNC Lathe & Milling Machines',
            'company' => 'Tata Motors Jamshedpur Works',
            'direction' => 'forward',
            'lot_type' => 'single',
            'reserve_price' => 4500000.00,
            'starting_price' => 3800000.00,
            'bid_increment' => 25000.00,
            'emd_amount' => 200000.00,
            'schedule_start' => now()->addDays(2)->toISOString(),
            'schedule_end' => now()->addDays(5)->toISOString(),
        ]);

        $createResponse->assertStatus(201);
        $code = $createResponse->json('data.code');

        // 2. Submit for Verification
        $submitResponse = $this->postJson("/api/v1/auctions/{$code}/submit");
        $submitResponse->assertStatus(200);

        // 3. Admin Verification & Approval
        $approveResponse = $this->postJson("/api/v1/auctions/{$code}/approve");
        $approveResponse->assertStatus(200);

        // 4. Publish
        $publishResponse = $this->postJson("/api/v1/auctions/{$code}/publish");
        $publishResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'published');
    }
}
