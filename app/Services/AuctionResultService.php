<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\AuctionResult;
use App\Models\Award;
use App\Models\SettlementLedgerEntry;
use App\Models\WinnerConfirmation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AuctionResultService
{
    /** Finalize once and preserve the ranking used at the close boundary. */
    public function finalize(Auction $auction, string $closeReason = 'ADMIN_CLOSE'): ?AuctionResult
    {
        if (! $auction->actual_started_at) return null;

        return DB::transaction(function () use ($auction, $closeReason) {
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();
            $existing = AuctionResult::where('auction_id', $auction->id)->first();
            if ($existing) return $existing;

            $slot = $auction->slots()->where('status', 'closed')->latest('sequence')->first();
            $direction = $auction->isReverse() ? 'L' : 'H';
            $bids = $auction->bids()->orderBy('amount', $auction->isReverse() ? 'asc' : 'desc')->orderBy('created_at')->orderBy('id')->get();
            $ranked = $bids->groupBy('vendor_id')->map(fn ($rows) => $rows->first())->sortBy([
                ['amount', $auction->isReverse() ? 'asc' : 'desc'], ['created_at', 'asc'], ['id', 'asc'],
            ])->values();
            $ranking = $ranked->map(fn ($bid, $index) => [
                'rank' => $direction.($index + 1),
                'bid_id' => $bid->id,
                'vendor_id' => $bid->vendor_id,
                'amount' => (string) $bid->amount,
                'server_received_at' => $bid->created_at?->toIso8601String(),
            ])->values()->all();
            $primary = $ranked->get(0);
            $fallback = $ranked->get(1);

            $result = AuctionResult::create([
                'auction_id' => $auction->id,
                'config_snapshot_id' => $auction->config_snapshot_id,
                'terms_version_id' => $auction->current_terms_version_id,
                'final_slot_id' => $slot?->id,
                'auction_type' => $auction->direction,
                'status' => 'provisional_winner',
                'close_reason' => $closeReason,
                'actual_started_at' => $auction->actual_started_at,
                'closed_at' => $auction->closed_at ?? now(),
                'final_value' => $primary?->amount,
                'winner_vendor_id' => $primary?->vendor_id,
                'second_rank_vendor_id' => $fallback?->vendor_id,
                'winner_bid_id' => $primary?->id,
                'second_rank_bid_id' => $fallback?->id,
                'ranking_snapshot' => $ranking,
            ]);

            $notification = app(NotificationService::class);
            foreach ($auction->bids()->whereNotNull('user_id')->distinct()->pluck('user_id') as $userId) {
                $isWinner = $primary && (int) $auction->bids()->where('user_id', $userId)->value('vendor_id') === (int) $primary->vendor_id;
                $notification->push(User::find($userId), $isWinner ? 'WINNER_CONFIRMATION_REQUIRED' : 'AUCTION_COMPLETED', $isWinner ? 'Winner confirmation required' : 'Auction completed', $auction->title.' has been closed and the server result is available.', ['auction_code' => $auction->code, 'result_id' => $result->id], "result:{$result->id}:user:{$userId}:".($isWinner ? 'winner-confirmation' : 'completed'));
            }

            if ($primary) {
                Award::firstOrCreate(
                    ['auction_id' => $auction->id, 'result_id' => $result->id],
                    ['code' => 'AWD-'.now()->year.'-'.str_pad((string) ($result->id), 4, '0', STR_PAD_LEFT), 'winner_vendor_id' => $primary->vendor_id, 'rank' => $direction.'1', 'award_amount' => $primary->amount, 'status' => 'offered', 'offered_at' => now(), 'acceptance_deadline' => now()->addHours((int) $auction->frozenConfig('winner_confirmation_hours', 48))],
                );
                WinnerConfirmation::firstOrCreate([
                    'result_id' => $result->id, 'participant_id' => $primary->vendor_id, 'rank' => $direction.'1',
                ], [
                    'auction_id' => $auction->id, 'offered_value' => $primary->amount,
                    'confirmation_status' => 'CONFIRMATION_PENDING',
                    'response_deadline' => now()->addHours((int) $auction->frozenConfig('winner_confirmation_hours', 48)),
                    'notified_at' => now(),
                ]);
            }

            $topTwo = collect([$primary?->vendor_id, $fallback?->vendor_id])->filter()->values()->all();
            $auction->emdTransactions()->where('status', 'locked')->get()->each(function ($emd) use ($auction, $result, $topTwo, $notification) {
                $isTopTwo = in_array($emd->vendor_id, $topTwo, true);
                $operation = $isTopTwo ? ($emd->vendor_id === $topTwo[0] ? 'EMD_RETAIN_PRIMARY' : 'EMD_RETAIN_FALLBACK') : 'EMD_REFUND_QUEUED';
                if (! $isTopTwo) $emd->update(['status' => 'refund_pending']);
                SettlementLedgerEntry::firstOrCreate(['idempotency_key' => "result:{$result->id}:emd:{$emd->id}:{$operation}"], [
                    'auction_id' => $auction->id, 'vendor_id' => $emd->vendor_id, 'emd_id' => $emd->id, 'result_id' => $result->id,
                    'config_snapshot_id' => $result->config_snapshot_id, 'terms_version_id' => $result->terms_version_id,
                    'operation_type' => $operation, 'amount' => $emd->amount, 'reason' => 'Auction result finalization', 'status' => 'queued',
                ]);
                $notification->push($emd->vendor?->user, $isTopTwo ? 'EMD_RETAINED' : 'REFUND_PENDING', $isTopTwo ? 'EMD retained pending settlement' : 'EMD refund pending', $auction->title, ['auction_code' => $auction->code, 'emd_id' => $emd->id, 'result_id' => $result->id], "result:{$result->id}:emd:{$emd->id}:".($isTopTwo ? 'emd-retained' : 'refund-pending'));
            });
            AuditLogger::write('AUCTION_RESULT_FINALIZED', 'auction_result', (string) $result->id, ['auction_id' => $auction->id, 'ranking_count' => count($ranking)]);
            return $result->fresh();
        });
    }
}
