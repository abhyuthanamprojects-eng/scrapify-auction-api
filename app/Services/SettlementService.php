<?php

namespace App\Services;

use App\Models\EmdTransaction;
use App\Models\SettlementLedgerEntry;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SettlementService
{
    public function startRefund(SettlementLedgerEntry $entry, string|int $method = 'MANUAL', ?float $amount = null, ?int $actorId = null): SettlementLedgerEntry
    {
        if (is_int($method) && $actorId === null) { $actorId = $method; $method = 'MANUAL'; }
        return DB::transaction(function () use ($entry, $method, $amount, $actorId) {
            $entry = SettlementLedgerEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status === 'completed') return $entry;
            if ($entry->operation_type !== 'EMD_REFUND_QUEUED') throw ValidationException::withMessages(['settlement' => 'Only queued EMD refunds can be initiated.']);
            $refundAmount = $amount ?? (float) $entry->amount;
            if ($refundAmount < 0 || $refundAmount > (float) $entry->amount) throw ValidationException::withMessages(['amount' => 'Refund amount must be within the queued ledger amount.']);
            $entry->update(['status' => 'REFUND_PROCESSING', 'refund_method' => $method, 'approved_amount' => $refundAmount, 'approved_by' => $actorId, 'approved_at' => now(), 'initiated_at' => now()]);
            AuditLogger::write('REFUND_INITIATED', 'settlement_ledger_entry', (string) $entry->id, ['amount' => (string) $entry->amount, 'emd_id' => $entry->emd_id]);
            app(NotificationService::class)->push($entry->emd?->vendor?->user, 'REFUND_PROCESSING', 'Refund processing started', 'Your EMD refund is being processed.', ['ledger_id' => $entry->id, 'auction_id' => $entry->auction_id], "ledger:{$entry->id}:refund-processing");
            return $entry->fresh();
        });
    }

    public function completeRefund(SettlementLedgerEntry $entry, string $reference, ?int $actorId = null): SettlementLedgerEntry
    {
        return DB::transaction(function () use ($entry, $reference, $actorId) {
            $entry = SettlementLedgerEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status === 'completed') return $entry;
            if (! in_array($entry->status, ['REFUND_PROCESSING', 'queued'], true)) throw ValidationException::withMessages(['settlement' => 'Refund is not ready for completion.']);
            $emd = EmdTransaction::whereKey($entry->emd_id)->lockForUpdate()->firstOrFail();
            if (! in_array($emd->status, ['refund_pending', 'refund_processing'], true)) throw ValidationException::withMessages(['emd' => "EMD is already {$emd->status}."]);
            $amount = $this->cents($entry->approved_amount ?? $entry->amount);
            $refunded = $this->cents($emd->refunded_amount);
            $forfeited = $this->cents($emd->forfeited_amount);
            $original = $this->cents($emd->amount);
            if ($refunded + $forfeited + $amount > $original) throw ValidationException::withMessages(['settlement' => 'Refund exceeds the remaining locked EMD.']);
            if ($amount > 0) app(WalletService::class)->unlock($emd->wallet, $amount / 100, 'emd_released', ['note' => 'Manual refund completed', 'auction_id' => $emd->auction_id, 'lot_id' => $emd->lot_id]);
            $newRefunded = $refunded + $amount;
            $emd->update(['refunded_amount' => $newRefunded / 100, 'status' => ($newRefunded + $forfeited >= $original ? 'refunded' : 'refund_pending'), 'released_at' => now(), 'reference' => $reference]);
            $entry->update(['status' => $newRefunded + $forfeited < $original ? 'partially_refunded' : 'completed', 'transaction_reference' => $reference, 'reference_number' => $reference, 'approved_by' => $actorId, 'approved_at' => $entry->approved_at ?? now(), 'completed_at' => now()]);
            AuditLogger::write('REFUND_COMPLETED', 'settlement_ledger_entry', (string) $entry->id, ['amount' => (string) $entry->amount, 'reference' => $reference, 'emd_id' => $emd->id]);
            app(NotificationService::class)->push($emd->vendor?->user, 'REFUND_COMPLETED', 'EMD refund completed', 'Your EMD refund has been completed.', ['ledger_id' => $entry->id, 'reference' => $reference], "ledger:{$entry->id}:refund-completed:{$reference}");
            return $entry->fresh();
        });
    }

    public function applyLossAdjustment(SettlementLedgerEntry $source, string $reason, string $policy, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($source, $reason, $policy, $actorId) {
            $source = SettlementLedgerEntry::whereKey($source->id)->lockForUpdate()->firstOrFail();
            $emd = EmdTransaction::whereKey($source->emd_id)->lockForUpdate()->firstOrFail();
            $requested = $this->cents($source->proposed_amount ?? $source->amount);
            $available = max(0, $this->cents($emd->amount) - $this->cents($emd->refunded_amount) - $this->cents($emd->forfeited_amount));
            $deduction = min($requested, $available);
            $key = "result:{$source->result_id}:emd:{$emd->id}:LOSS_ADJUSTMENT:{$deduction}";
            $adjustment = SettlementLedgerEntry::firstOrCreate(['idempotency_key' => $key], [
                'auction_id' => $source->auction_id, 'vendor_id' => $source->vendor_id, 'emd_id' => $emd->id, 'result_id' => $source->result_id,
                'config_snapshot_id' => $source->config_snapshot_id, 'terms_version_id' => $source->terms_version_id, 'operation_type' => 'LOSS_ADJUSTMENT',
                'amount' => $deduction / 100, 'proposed_amount' => $requested / 100, 'approved_amount' => $deduction / 100,
                'reason' => $reason, 'approval_reason' => $policy, 'status' => 'applied', 'approved_by' => $actorId, 'approved_at' => now(), 'completed_at' => now(),
            ]);
            if ($adjustment->wasRecentlyCreated && $deduction > 0) {
                app(WalletService::class)->unlock($emd->wallet, $deduction / 100, 'emd_forfeited', ['note' => $reason, 'auction_id' => $emd->auction_id, 'lot_id' => $emd->lot_id]);
                $forfeited = $this->cents($emd->forfeited_amount) + $deduction;
                $remaining = max(0, $this->cents($emd->amount) - $this->cents($emd->refunded_amount) - $forfeited);
                $emd->update(['forfeited_amount' => $forfeited / 100, 'status' => $remaining === 0 ? 'forfeited' : 'refund_pending']);
                if ($remaining > 0) SettlementLedgerEntry::firstOrCreate(['idempotency_key' => "result:{$source->result_id}:emd:{$emd->id}:REFUND_AFTER_ADJUSTMENT"], [
                    'auction_id' => $source->auction_id, 'vendor_id' => $source->vendor_id, 'emd_id' => $emd->id, 'result_id' => $source->result_id,
                    'config_snapshot_id' => $source->config_snapshot_id, 'terms_version_id' => $source->terms_version_id, 'operation_type' => 'EMD_REFUND_QUEUED',
                    'amount' => $remaining / 100, 'reason' => 'Remaining EMD after loss adjustment', 'status' => 'queued',
                ]);
            }
            AuditLogger::write('LOSS_ADJUSTMENT_APPLIED', 'settlement_ledger_entry', (string) $adjustment->id, ['requested' => $requested / 100, 'applied' => $deduction / 100, 'policy' => $policy]);
            app(NotificationService::class)->push($emd->vendor?->user, $deduction >= $this->cents($emd->amount) ? 'EMD_FORFEITED' : 'EMD_PARTIALLY_FORFEITED', 'EMD settlement adjustment recorded', 'An EMD settlement adjustment has been recorded against the auction result.', ['ledger_id' => $adjustment->id, 'amount' => $deduction / 100, 'policy' => $policy], "ledger:{$adjustment->id}:loss-adjustment");
            return ['adjustment' => $adjustment->fresh(), 'emd' => $emd->fresh(), 'applied_cents' => $deduction];
        });
    }

    private function cents(mixed $value): int { return (int) round(((float) ($value ?? 0)) * 100); }
}
