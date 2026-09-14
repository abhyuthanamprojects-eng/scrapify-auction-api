<?php

namespace Tests\Feature;

use App\Models\GeneralSetting;
use App\Models\RegistrationPromotion;
use App\Models\User;
use App\Models\Vendor;
use App\Services\RegistrationPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_fee_is_read_from_general_settings_and_percentage_promo_is_capped(): void
    {
        GeneralSetting::updateOrCreate(['key' => 'vendor_registration_fee'], ['value' => '7500']);
        $promotion = RegistrationPromotion::create([
            'code' => 'WELCOME25',
            'discount_type' => 'percentage',
            'discount_value' => 25,
            'maximum_discount' => 1000,
            'active' => true,
        ]);

        $pricing = app(RegistrationPricingService::class)->quote('welcome25');

        $this->assertSame(7500.0, $pricing['base_amount']);
        $this->assertSame(1000.0, $pricing['discount_amount']);
        $this->assertSame(6500.0, $pricing['payable_amount']);
        $this->assertSame('WELCOME25', $pricing['promo_code']);
        $this->assertSame(0, $promotion->fresh()->redemption_count);
    }

    public function test_promo_quote_is_rejected_after_the_same_vendor_has_used_the_code(): void
    {
        $user = User::factory()->create(['role' => 'buyer']);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'company_name' => 'Test Vendor',
            'contact_name' => 'Test Contact',
            'email' => $user->email,
            'phone' => '9999999999',
        ]);
        RegistrationPromotion::create([
            'code' => 'FIRSTPAY',
            'discount_type' => 'fixed',
            'discount_value' => 500,
            'active' => true,
        ]);
        $vendor->payments()->create([
            'reference' => 'previous-payment',
            'amount' => 4500,
            'method' => 'UPI',
            'status' => 'pending',
            'meta' => ['promo_code' => 'FIRSTPAY'],
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(RegistrationPricingService::class)->quoteForVendor($vendor, 'firstpay');
    }
}
