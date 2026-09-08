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
        $config = $auction->configSnapshot?->config ?? [];
        if (array_key_exists('emd_required', $config) && ! $config['emd_required']) $amount = 0.0;
        elseif (($config['emd_type'] ?? 'PERCENTAGE') === 'FIXED') $amount = (float) ($config['emd_fixed_amount'] ?? $auction->emd_amount);
        elseif ($auction->final_rfq_value !== null) $amount = round((float) $auction->final_rfq_value * ((float) ($config['emd_percentage'] ?? 10) / 100), 2);
        else $amount = (float) $auction->emd_amount;

        if ($amount <= 0) {
            // No EMD configured for this auction — record a zero hold so the
            // bidding path stays uniform.
            return EmdTransaction::updateOrCreate(
                ['auction_id' => $auction->id, 'lot_id' => $lotId, 'vendor_id' => $vendor->id],
                ['wallet_id' => $wallet->id, 'amount' => 0, 'required_amount' => 0, 'paid_amount' => 0, 'verified_amount' => 0, 'status' => 'locked', 'locked_at' => now(), 'config_snapshot_id' => $auction->config_snapshot_id],
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
                'required_amount' => $amount, 'paid_amount' => $amount, 'verified_amount' => $amount, 'config_snapshot_id' => $auction->config_snapshot_id,
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
