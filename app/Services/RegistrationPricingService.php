<?php

namespace App\Services;

use App\Models\RegistrationPromotion;
use App\Models\Vendor;
use Illuminate\Validation\ValidationException;

final class RegistrationPricingService
{
    public function quote(?string $code = null): array
    {
        $base = round(GeneralSettings::decimal('vendor_registration_fee', (float) config('scrapify.vendor_registration_fee', 5000)), 2);
        if (! $code) {
            return $this->result($base, null, 0, $base);
        }

        $promotion = RegistrationPromotion::query()->available()->where('code', strtoupper(trim($code)))->first();
        if (! $promotion) {
            throw ValidationException::withMessages(['promo_code' => 'This promo code is invalid, inactive, expired, or fully redeemed.']);
        }
        if ($base < (float) $promotion->minimum_amount) {
            throw ValidationException::withMessages(['promo_code' => 'This promo code is not valid for the current registration fee.']);
        }

        $discount = $promotion->discount_type === 'percentage'
            ? $base * ((float) $promotion->discount_value / 100)
            : (float) $promotion->discount_value;
        if ($promotion->maximum_discount !== null) {
            $discount = min($discount, (float) $promotion->maximum_discount);
        }
        $discount = min(max(round($discount, 2), 0), $base);
        return $this->result($base, $promotion, $discount, round($base - $discount, 2));
    }

    public function quoteForVendor(Vendor $vendor, ?string $code = null): array
    {
        $result = $this->quote($code);
        if ($code && $vendor->payments()->where('meta->promo_code', strtoupper(trim($code)))->exists()) {
            throw ValidationException::withMessages(['promo_code' => 'This promo code has already been used for this registration.']);
        }
        return $result;
    }

    private function result(float $base, ?RegistrationPromotion $promotion, float $discount, float $total): array
    {
        return [
            'currency' => 'INR',
            'base_amount' => $base,
            'discount_amount' => $discount,
            'payable_amount' => $total,
            'promo_code' => $promotion?->code,
            'promo_description' => $promotion?->description,
        ];
    }
}
