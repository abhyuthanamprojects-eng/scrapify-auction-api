<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\SettlementLedgerEntry;
use App\Services\AuditLogger;
use App\Services\EmdService;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettlementController extends Controller
{
    public function show(Request $request, string $code): JsonResponse
    {
        abort_unless($request->user()?->hasRole('admin', 'super_admin', 'operations', 'finance_manager', 'auditor'), 403, 'Settlement details are restricted to authorized staff.');
        $auction = Auction::where('code', $code)->firstOrFail();
        $result = $auction->result;

        return response()->json([
            'success' => true,
            'data' => [
                'auction' => $auction->only(['id', 'code', 'status', 'direction', 'final_price', 'winner_vendor_id']),
                'result' => $result,
                'ledger' => SettlementLedgerEntry::where('auction_id', $auction->id)->orderBy('id')->get(),
                'emd' => $auction->emdTransactions()->with('vendor:id,company_name')->orderBy('id')->get(),
                'terms_version_id' => $result?->terms_version_id,
                'config_snapshot_id' => $result?->config_snapshot_id,
            ],
        ]);
    }

    public function startRefund(Request $request, int $id): JsonResponse
    {
        $entry = SettlementLedgerEntry::findOrFail($id);
        $data = $request->validate(['refund_method' => ['required', 'string', 'max:40'], 'amount' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $entry->update(['notes' => $data['notes'] ?? null]);
        return response()->json(['success' => true, 'data' => app(\App\Services\SettlementService::class)->startRefund($entry, $data['refund_method'], $data['amount'] ?? null, auth()->id())]);
    }

    public function completeRefund(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:120']]);
        $entry = SettlementLedgerEntry::findOrFail($id);
        return response()->json(['success' => true, 'data' => app(\App\Services\SettlementService::class)->completeRefund($entry, $data['reference'], auth()->id())]);
    }

    public function applyLoss(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0'], 'reason' => ['required', 'string', 'max:2000'], 'policy' => ['required', 'in:ACTUAL_LOSS_ONLY,PARTIAL_FORFEITURE,FULL_EMD_FORFEITURE']]);
        $entry = SettlementLedgerEntry::findOrFail($id);
        $entry->update(['proposed_amount' => $data['amount']]);
        return response()->json(['success' => true, 'data' => app(\App\Services\SettlementService::class)->applyLossAdjustment($entry, $data['reason'], $data['policy'], auth()->id())]);
    }

    public function releaseFallback(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $result = $auction->result;
        abort_unless($result && $result->second_rank_vendor_id, 422, 'No fallback participant is retained for this result.');
        $emd = $auction->emdTransactions()->where('vendor_id', $result->second_rank_vendor_id)->where('status', 'locked')->first();
        if (! $emd) return response()->json(['success' => true, 'message' => 'Fallback EMD was already released.', 'data' => $result]);

        app(EmdService::class)->release($emd, 'Admin released fallback EMD after settlement decision');
        $entry = SettlementLedgerEntry::firstOrCreate(['idempotency_key' => "result:{$result->id}:emd:{$emd->id}:ADMIN_RELEASE_FALLBACK"], [
            'auction_id' => $auction->id, 'vendor_id' => $emd->vendor_id, 'emd_id' => $emd->id, 'result_id' => $result->id,
            'config_snapshot_id' => $result->config_snapshot_id, 'terms_version_id' => $result->terms_version_id,
            'operation_type' => 'EMD_RELEASE_FALLBACK', 'amount' => $emd->amount, 'reason' => 'Admin settlement release', 'status' => 'applied',
        ]);
        AuditLogger::write('SECOND_RANK_EMD_RELEASED', 'settlement_ledger_entry', (string) $entry->id, ['auction_id' => $auction->id, 'participant_id' => $emd->vendor_id]);
        return response()->json(['success' => true, 'data' => $entry->fresh()]);
    }

    public function completeSettlement(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $result = $auction->result;
        abort_unless($result, 422, 'Auction has no authoritative result.');
        $open = SettlementLedgerEntry::where('auction_id', $auction->id)->whereIn('status', ['queued', 'REFUND_PROCESSING'])->exists();
        abort_if($open, 422, 'Settlement cannot be completed while refunds remain queued or processing.');
        abort_unless(in_array($result->status, ['provisional_winner', 'fallback_confirmed', 'FALLBACK_EXHAUSTED_REVIEW_REQUIRED'], true), 422, 'Result is not in a completable state.');
        $result->update(['status' => 'settled']);
        AuditLogger::write('SETTLEMENT_COMPLETED', 'auction_result', (string) $result->id, ['auction_id' => $auction->id]);
        return response()->json(['success' => true, 'data' => $result->fresh()]);
    }
}
