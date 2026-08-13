<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\PaymentMethod;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * Addresses and payment methods from the mobile app's MoreScreens.tsx mocks.
 * Payment method labels are the same masked display strings the screen shows —
 * no real card or account numbers exist in this data.
 */
class ProfileSeeder extends Seeder
{
    private const ADDRESSES = [
        ['Warehouse', 'GreenCycle Recyclers', 'Plot 41, Sector 8, MIDC Industrial Area', 'Pune', 'Maharashtra', '411019', '+91 98765 43210', true],
        ['Head Office', 'Rahul Sharma', '204, Green Heights, Baner Road', 'Pune', 'Maharashtra', '411045', '+91 98765 43210', false],
    ];

    private const METHODS = [
        ['UPI', 'rahul@okaxis', 'Google Pay', true],
        ['Card', '•••• 4821', 'HDFC Bank Visa', false],
        ['Bank', 'HDFC •••• 8912', 'Auto-debit enabled', false],
    ];

    public function run(): void
    {
        foreach (Vendor::where('status', 'approved')->whereNotNull('user_id')->get() as $vendor) {
            foreach (self::ADDRESSES as [$label, $name, $line, $city, $state, $pin, $phone, $default]) {
                Address::firstOrCreate(
                    ['user_id' => $vendor->user_id, 'label' => $label],
                    [
                        'name' => $name, 'line' => $line, 'city' => $city, 'state' => $state,
                        'pincode' => $pin, 'phone' => $phone, 'is_default' => $default,
                    ],
                );
            }

            foreach (self::METHODS as [$type, $label, $subtitle, $primary]) {
                PaymentMethod::firstOrCreate(
                    ['user_id' => $vendor->user_id, 'label' => $label],
                    ['type' => $type, 'subtitle' => $subtitle, 'is_primary' => $primary],
                );
            }
        }
    }
}
