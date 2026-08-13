<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use Illuminate\Database\Seeder;

/**
 * Opening balances so the mobile wallet screen has something to show.
 * Mirrors the demo ledger in the mobile app's src/lib/wallet-store.ts
 * (balance ₹42,850 with ₹20,000 held as EMD) for the first approved vendor.
 */
class WalletSeeder extends Seeder
{
    public function run(): void
    {
        foreach (User::all() as $user) {
            Wallet::firstOrCreate(
                ['user_id' => $user->id],
                ['vendor_id' => $user->vendor_id, 'balance' => 0, 'locked' => 0],
            );
        }

        foreach (Vendor::where('status', 'approved')->get() as $vendor) {
            if (! $vendor->user_id) {
                continue;
            }

            $wallet = Wallet::where('user_id', $vendor->user_id)->first();
            $wallet->update(['balance' => 42_850, 'locked' => 0]);

            $wallet->transactions()->firstOrCreate(
                ['reference' => 'TXN20260724A1-'.$vendor->code],
                [
                    'type' => 'add_money',
                    'amount' => 42_850,
                    'balance_after' => 42_850,
                    'note' => 'Opening balance — seeded for local testing',
                    'method' => 'UPI',
                    'status' => 'success',
                ],
            );
        }
    }
}
