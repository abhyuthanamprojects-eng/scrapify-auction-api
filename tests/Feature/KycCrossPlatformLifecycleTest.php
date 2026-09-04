<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\Lot;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KycCrossPlatformLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('public');
    }

    public function test_full_buyer_and_seller_kyc_lifecycle_and_security_restrictions(): void
    {
        // 1. Register new buyer
        $registerRes = $this->postJson('/api/v1/auth/register', [
            'name' => 'Aditya Birla Metals Lead',
            'email' => 'birla.metals@test.com',
            'phone' => '9820098200',
            'password' => 'Password@1234',
            'role' => 'buyer',
            'company_name' => 'Aditya Birla Metal Recycling Ltd',
        ]);
        $registerRes->assertStatus(201);
        $token = $registerRes->json('token');
        $vendorCode = $registerRes->json('user.vendor.code');
        $this->assertNotEmpty($vendorCode);

        // 2. Save Business Step
        $step2Res = $this->withToken($token)->postJson('/api/v1/vendors/save-step', [
            'step' => 2,
            'company_name' => 'Aditya Birla Metal Recycling Ltd',
            'trade_name' => 'Birla Metals',
            'business_type' => 'Private Limited (Pvt Ltd)',
            'cin_number' => 'U27100MH2010PTC123456',
            'address_line1' => 'Birla House, Worli',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'pincode' => '400030',
        ]);
        $step2Res->assertStatus(200);

        // 3. Save GST & Bank Step
        $step3Res = $this->withToken($token)->postJson('/api/v1/vendors/save-step', [
            'step' => 3,
            'gst_number' => '27AAACB1234N1Z5',
            'pan_number' => 'AAACB1234N',
            'bank_name' => 'HDFC Bank Ltd',
            'account_number' => '50200012345678',
            'ifsc_code' => 'HDFC0000060',
            'account_holder_name' => 'Aditya Birla Metal Recycling Ltd',
            'account_type' => 'Current Account',
            'terms_accepted' => true,
        ]);
        $step3Res->assertStatus(200);
        $this->assertEquals('valid', $step3Res->json('vendor.gst_status'));
        $this->assertEquals('valid', $step3Res->json('vendor.bank_status'));

        // 4. Upload KYC Documents
        $file = UploadedFile::fake()->create('gst_certificate.pdf', 500, 'application/pdf');
        $docRes = $this->withToken($token)->postJson("/api/v1/vendors/{$vendorCode}/documents", [
            'doc_key' => 'gst_certificate',
            'kind' => 'GST Registration Certificate',
            'file' => $file,
        ]);
        $docRes->assertStatus(201);
        $this->assertEquals('processed', $docRes->json('ocr.status'));

        // 5. Submit KYC for Verification
        $submitRes = $this->withToken($token)->postJson("/api/v1/vendors/{$vendorCode}/submit-kyc");
        $submitRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'kyc_status' => 'pending',
            ]);

        // 6. Security Restriction Check: Pending user cannot place bid
        $liveAuction = Auction::where('status', 'live')->first();
        if (!$liveAuction) {
            $seller = Vendor::where('status', 'approved')->first();
            $liveAuction = Auction::create([
                'title' => 'Test Heavy Scrap Lot',
                'category_id' => 1,
                'seller_vendor_id' => $seller->id,
                'status' => 'live',
                'start_price' => 100000,
                'reserve_price' => 120000,
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHours(2),
            ]);
        }

        $bidRes = $this->withToken($token)->postJson("/api/v1/auctions/{$liveAuction->code}/bids", [
            'amount' => 150000,
        ]);
        $bidRes->assertStatus(403)
            ->assertJson([
                'code' => 'KYC_VERIFICATION_REQUIRED',
                'kyc_status' => 'pending',
            ]);

        // 7. Admin Inspection & Rejection
        $admin = User::where('role', 'super_admin')->first();
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $rejectRes = $this->postJson("/api/v1/vendors/{$vendorCode}/reject", [
            'reason' => 'Address proof unclear, please upload registered lease agreement.',
        ]);
        $rejectRes->assertStatus(200);

        // 8. Rejected user receives KYC_REJECTED with reason
        $buyerUser = User::where('email', 'birla.metals@test.com')->first();
        \Laravel\Sanctum\Sanctum::actingAs($buyerUser);

        $bidAfterReject = $this->postJson("/api/v1/auctions/{$liveAuction->code}/bids", [
            'amount' => 150000,
        ]);
        $bidAfterReject->assertStatus(403)
            ->assertJson([
                'code' => 'KYC_REJECTED',
                'kyc_status' => 'rejected',
                'rejection_reason' => 'Address proof unclear, please upload registered lease agreement.',
            ]);

        // 9. Resubmit corrected KYC
        $resubmitRes = $this->postJson("/api/v1/vendors/{$vendorCode}/resubmit-kyc");
        $resubmitRes->assertStatus(200)
            ->assertJson([
                'success' => true,
                'kyc_status' => 'pending',
            ]);

        // 10. Admin Approves KYC
        \Laravel\Sanctum\Sanctum::actingAs($admin);
        $approveRes = $this->postJson("/api/v1/vendors/{$vendorCode}/approve");
        $approveRes->assertStatus(200)
            ->assertJson([
                'data' => [
                    'status' => 'approved',
                    'can_bid' => true,
                ],
            ]);

        // 11. Verified Buyer Bids Successfully
        $buyerUser = $buyerUser->fresh(['vendor', 'wallet']);
        \Laravel\Sanctum\Sanctum::actingAs($buyerUser);
        $buyerUser->wallet->update(['balance' => 10000000]);

        $lot = $liveAuction->lots()->first();
        $successfulBid = $this->postJson("/api/v1/auctions/{$liveAuction->code}/bids", [
            'amount' => 3100000,
            'lot' => $lot?->code,
        ]);
        $successfulBid->assertStatus(201);
    }
}
