<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The wallet is a ledger, not a money store. `wallet_transactions` is the
 * record of truth; `wallets.balance` / `wallets.locked` are cached rollups
 * kept in step inside the same transaction as every entry.
 */
class WalletService
{
    public function forUser(User $user): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['vendor_id' => $user->vendor_id, 'balance' => 0, 'locked' => 0],
        );
    }

    public static function reference(string $prefix = 'TXN'): string
    {
        return $prefix.now()->format('Ymd').Str::upper(Str::random(5));
    }

    public function credit(Wallet $wallet, string $type, float $amount, array $attributes = []): WalletTransaction
    {
        return $this->entry($wallet, $type, abs($amount), $attributes);
    }

    public function debit(Wallet $wallet, string $type, float $amount, array $attributes = []): WalletTransaction
    {
        return $this->entry($wallet, $type, -abs($amount), $attributes);
    }

    /** Move funds from available to locked without changing the balance. */
    public function lock(Wallet $wallet, float $amount, array $attributes = []): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $attributes) {
            $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            if ($wallet->available() < $amount) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'Insufficient wallet balance. Available %s, required %s.',
                        number_format($wallet->available(), 2),
                        number_format($amount, 2),
                    ),
                ]);
            }

            $wallet->increment('locked', $amount);

            return $this->record($wallet->fresh(), 'emd_locked', -$amount, $attributes);
        });
    }

    public function unlock(Wallet $wallet, float $amount, string $type = 'emd_released', array $attributes = []): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $amount, $type, $attributes) {
            $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            $release = min($amount, (float) $wallet->locked);
            $wallet->decrement('locked', $release);

            // Forfeit takes the money out of the balance as well as the hold.
            if ($type === 'emd_forfeited') {
                $wallet->decrement('balance', $release);
            }

            return $this->record($wallet->fresh(), $type, $type === 'emd_forfeited' ? -$release : $release, $attributes);
        });
    }

    private function entry(Wallet $wallet, string $type, float $signedAmount, array $attributes): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $type, $signedAmount, $attributes) {
            $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

            if ($signedAmount < 0 && $wallet->available() < abs($signedAmount)) {
                throw ValidationException::withMessages([
                    'amount' => 'Insufficient wallet balance.',
                ]);
            }

            $wallet->increment('balance', $signedAmount);

            return $this->record($wallet->fresh(), $type, $signedAmount, $attributes);
        });
    }

    private function record(Wallet $wallet, string $type, float $signedAmount, array $attributes): WalletTransaction
    {
        return WalletTransaction::create(array_merge([
            'wallet_id' => $wallet->id,
            'type' => $type,
            'amount' => $signedAmount,
            'balance_after' => $wallet->balance,
            'status' => 'success',
            'reference' => self::reference(match ($type) {
                'emd_locked', 'emd_released', 'emd_forfeited' => 'EMD',
                'payment' => 'PAY',
                'refund' => 'RFN',
                'payout' => 'PYT',
                default => 'TXN',
            }),
        ], $attributes));
    }
}
