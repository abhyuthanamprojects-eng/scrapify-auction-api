<?php

namespace App\Console\Commands;

use App\Models\Award;
use App\Models\WinnerConfirmation;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireWinnerConfirmations extends Command
{
    protected $signature = 'auctions:expire-winner-confirmations';
    protected $description = 'Move overdue winner confirmations to server-controlled review/default state.';

    public function handle(): int
    {
        $count = 0;
        WinnerConfirmation::where('confirmation_status', 'CONFIRMATION_PENDING')
            ->whereNotNull('response_deadline')->where('response_deadline', '<', now())
            ->each(function (WinnerConfirmation $confirmation) use (&$count) {
                DB::transaction(function () use ($confirmation, &$count) {
                    $locked = WinnerConfirmation::whereKey($confirmation->id)->lockForUpdate()->first();
                    if (! $locked || $locked->confirmation_status !== 'CONFIRMATION_PENDING' || ! $locked->response_deadline?->isPast()) return;
                    $locked->update(['confirmation_status' => 'REVIEW_REQUIRED', 'responded_at' => now(), 'default_reason' => 'Winner response deadline expired on the server.']);
                    Award::where('result_id', $locked->result_id)->where('winner_vendor_id', $locked->participant_id)->where('status', 'offered')->update(['status' => 'defaulted', 'default_reason' => $locked->default_reason]);
                    AuditLogger::write('WINNER_CONFIRMATION_EXPIRED', 'winner_confirmation', (string) $locked->id, ['auction_id' => $locked->auction_id, 'participant_id' => $locked->participant_id]);
                    $count++;
                });
            });
        $this->info("Expired {$count} winner confirmations.");
        return self::SUCCESS;
    }
}
