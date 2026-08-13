<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\EmdTransaction;
use App\Models\Vendor;
use Illuminate\Validation\ValidationException;

class EmdService
{
    public function __construct(private WalletService $wallets)
    {
    }

    /**
     * Lock EMD for a vendor on an auction. Idempotent — calling it twice for
     * the same auction/lot/vendor returns the existing hold rather than
     * double-charging, which matters because place-bid calls it every time.
     */
    public function ensureLocked(Auction $auction, Vendor $vendor, ?int $lotId = null): EmdTransaction
    {
        $existing = EmdTransaction::where('auction_id', $auction->id)
            ->where('lot_id', $lotId)
            ->where('vendor_id', $vendor->id)
            ->first();

        if ($existing && $existing->status === 'locked') {
            return $existing;
        }

        $user = $vendor->user;

        if (! $user) {
            throw ValidationException::withMessages([
                'vendor' => 'This vendor has no linked user account, so no wallet to draw EMD from.',
            ]);
        }

        $wallet = $this->wallets->forUser($user);
        $amount = (float) $auction->emd_amount;

        if ($amount <= 0) {
            // No EMD configured for this auction — record a zero hold so the
            // bidding path stays uniform.
            return EmdTransaction::updateOrCreate(
                ['auction_id' => $auction->id, 'lot_id' => $lotId, 'vendor_id' => $vendor->id],
                ['wallet_id' => $wallet->id, 'amount' => 0, 'status' => 'locked', 'locked_at' => now()],
            );
        }

        $txn = $this->wallets->lock($wallet, $amount, [
            'note' => "EMD — {$auction->title}",
            'method' => 'Wallet',
            'auction_id' => $auction->id,
            'lot_id' => $lotId,
        ]);

        return EmdTransaction::updateOrCreate(
            ['auction_id' => $auction->id, 'lot_id' => $lotId, 'vendor_id' => $vendor->id],
            [
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'status' => 'locked',
                'reference' => $txn->reference,
                'locked_at' => now(),
                'released_at' => null,
            ],
        );
    }

    public function release(EmdTransaction $emd, string $reason = 'Auction closed'): EmdTransaction
    {
        if ($emd->status !== 'locked') {
            throw ValidationException::withMessages([
                'emd' => "EMD is already {$emd->status}.",
            ]);
        }

        if ((float) $emd->amount > 0) {
            $this->wallets->unlock($emd->wallet, (float) $emd->amount, 'emd_released', [
                'note' => $reason,
                'method' => 'Wallet',
                'auction_id' => $emd->auction_id,
                'lot_id' => $emd->lot_id,
            ]);
        }

        $emd->update(['status' => 'released', 'released_at' => now(), 'note' => $reason]);

        return $emd;
    }

    public function forfeit(EmdTransaction $emd, string $reason): EmdTransaction
    {
        if ($emd->status !== 'locked') {
            throw ValidationException::withMessages([
                'emd' => "EMD is already {$emd->status}.",
            ]);
        }

        if ((float) $emd->amount > 0) {
            $this->wallets->unlock($emd->wallet, (float) $emd->amount, 'emd_forfeited', [
                'note' => $reason,
                'method' => 'Wallet',
                'auction_id' => $emd->auction_id,
                'lot_id' => $emd->lot_id,
            ]);
        }

        $emd->update(['status' => 'forfeited', 'released_at' => now(), 'note' => $reason]);

        return $emd;
    }

    /** Release every losing bidder's hold when an auction closes. */
    public function releaseLosers(Auction $auction): int
    {
        $winnerId = $auction->winner_vendor_id;
        $released = 0;

        EmdTransaction::where('auction_id', $auction->id)
            ->where('status', 'locked')
            ->when($winnerId, fn ($q) => $q->where('vendor_id', '!=', $winnerId))
            ->each(function (EmdTransaction $emd) use (&$released, $auction) {
                $this->release($emd, "Auction {$auction->code} closed — not the winning bid");
                $released++;
            });

        return $released;
    }
}
