<?php

namespace Database\Seeders;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

/**
 * The five demo notifications and the preference groups from the mobile app
 * (MoreScreens.tsx seedNotifs / prefGroups), attached to approved vendors.
 */
class NotificationSeeder extends Seeder
{
    private const NOTIFS = [
        ['outbid', "You've been outbid", 'PCB Boards Scrap — Grade A • new bid ₹8,15,000', false, 2],
        ['starting', 'Auction starting soon', 'Copper Cable Scrap Bundle starts in 15 min', false, 12],
        ['won', 'You won a lot!', 'Refurb Smartphones — 300 pcs • ₹4,52,000', true, 60],
        ['payment', 'Payment received', 'Balance ₹4,42,000 credited to seller', true, 120],
        ['kyc', 'KYC approved', 'Bidding is now unlocked. Happy bidding!', true, 1440],
    ];

    private const PREFS = [
        ['Bidding', 'outbid_alerts'],
        ['Bidding', 'auction_starting'],
        ['Bidding', 'auction_ending'],
        ['Orders', 'payment_reminders'],
        ['Orders', 'pickup_updates'],
        ['Account', 'kyc_updates'],
        ['Account', 'promotions'],
    ];

    public function run(): void
    {
        foreach (Vendor::where('status', 'approved')->whereNotNull('user_id')->get() as $vendor) {
            foreach (self::NOTIFS as [$type, $title, $body, $read, $minutesAgo]) {
                Notification::firstOrCreate(
                    ['user_id' => $vendor->user_id, 'type' => $type, 'title' => $title],
                    [
                        'body' => $body,
                        'read_at' => $read ? now()->subMinutes($minutesAgo) : null,
                        'created_at' => now()->subMinutes($minutesAgo),
                        'updated_at' => now()->subMinutes($minutesAgo),
                    ],
                );
            }

            foreach (self::PREFS as [$group, $key]) {
                NotificationPreference::firstOrCreate(
                    ['user_id' => $vendor->user_id, 'key' => $key],
                    ['group' => $group, 'enabled' => $key !== 'promotions'],
                );
            }
        }
    }
}
