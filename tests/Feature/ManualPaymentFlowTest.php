<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\EmdTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_proof_is_admin_verified_then_wallet_is_credited_after_reference_confirmation(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        Vendor::create(['user_id' => $buyer->id, 'company_name' => 'Buyer Co', 'contact_name' => 'Buyer', 'email' => $buyer->email, 'phone' => '9999999999', 'status' => 'approved']);

        Sanctum::actingAs($buyer);
        $submitted = $this->post('/api/v1/payments/manual', [
            'purpose' => 'wallet_topup', 'amount' => 500, 'transaction_id' => 'UTR-123',
            'proof' => UploadedFile::fake()->create('bank-proof.jpg', 20, 'image/jpeg'),
        ]);
        $submitted->assertCreated()->assertJsonPath('payment.status', 'pending');
        $paymentId = $submitted->json('payment.id');

        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'status' => 'active']));
        $reviewed = $this->postJson("/api/v1/admin/payments/manual/{$paymentId}/verify", ['status' => 'verified']);
        $reviewed->assertOk();
        $reference = $reviewed->json('payment.verification_reference');
        $this->assertNotEmpty($reference);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/payments/manual/{$paymentId}/confirm", ['verification_reference' => $reference])->assertOk();
        $this->assertSame(500.0, (float) $buyer->fresh()->wallet->balance);
        $this->assertSame('success', Payment::find($paymentId)->status);
    }

    public function test_emd_bank_proof_confirmation_locks_the_auction_emd(): void
    {
        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active']);
        $vendor = Vendor::create(['user_id' => $buyer->id, 'company_name' => 'Buyer Co', 'contact_name' => 'Buyer', 'email' => $buyer->email, 'phone' => '9999999998', 'status' => 'approved']);
        $auction = Auction::create(['title' => 'Bank EMD auction', 'company' => 'Scrapify', 'status' => 'published', 'direction' => 'forward', 'emd_amount' => 250]);

        Sanctum::actingAs($buyer);
        $submitted = $this->post('/api/v1/payments/manual', [
            'purpose' => 'emd', 'amount' => 250, 'target_code' => $auction->code,
            'proof' => UploadedFile::fake()->create('emd-proof.jpg', 20, 'image/jpeg'),
        ]);
        $paymentId = $submitted->assertCreated()->json('payment.id');

        Sanctum::actingAs(User::factory()->create(['role' => 'super_admin', 'status' => 'active']));
        $reviewed = $this->postJson("/api/v1/admin/payments/manual/{$paymentId}/verify", ['status' => 'verified'])->assertOk();

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/payments/manual/{$paymentId}/confirm", ['verification_reference' => $reviewed->json('payment.verification_reference')])->assertOk();
        $this->assertSame('locked', EmdTransaction::where('auction_id', $auction->id)->where('vendor_id', $vendor->id)->value('status'));
    }
}
