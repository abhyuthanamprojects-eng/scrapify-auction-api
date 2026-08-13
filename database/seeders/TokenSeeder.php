<?php

namespace Database\Seeders;

use App\Models\AccessToken;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The four live-access tokens from the admin panel's auctions-store.ts
 * tseed() — same codes, same token strings, same statuses.
 */
class TokenSeeder extends Seeder
{
    private const TOKENS = [
        ['T-9001', 'tkn_A83HD2K9QP', 'AUC-2026-0025', 'view_only', 48, 'active', -24],
        ['T-9002', 'tkn_KQ8FN0LZ21', 'AUC-2026-0025', 'can_bid', 24, 'active', -24],
        ['T-8990', 'tkn_M2X7W3PQ88', 'AUC-2026-0021', 'can_bid', -48, 'expired', -240],
        ['T-8975', 'tkn_ZR1H5T0V2N', 'AUC-2026-0018', 'view_only', -100, 'revoked', -260],
    ];

    public function run(): void
    {
        $creator = User::where('role', 'admin')->first();

        foreach (self::TOKENS as [$code, $token, $auctionCode, $type, $expiresIn, $status, $createdAt]) {
            $auctionId = Auction::where('code', $auctionCode)->value('id');

            if (! $auctionId) {
                continue;
            }

            AccessToken::updateOrCreate(
                ['code' => $code],
                [
                    'token' => $token,
                    'auction_id' => $auctionId,
                    'type' => $type,
                    'status' => $status === 'expired' ? 'active' : $status, // expiry is derived
                    'expires_at' => now()->addHours($expiresIn),
                    'created_by' => $creator?->id,
                    'revoked_at' => $status === 'revoked' ? now()->addHours($createdAt) : null,
                    'created_at' => now()->addHours($createdAt),
                    'updated_at' => now()->addHours($createdAt),
                ],
            );
        }
    }
}
