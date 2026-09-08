<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Award;
use App\Models\AuctionResult;
use App\Models\FallbackOffer;
use App\Models\Order;
use App\Models\SettlementLedgerEntry;
use App\Models\Vendor;
use App\Models\WinnerConfirmation;
use App\Services\AuditLogger;
use App\Services\EmdService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AwardController extends Controller
{
    public function index(string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $awards = Award::where('auction_id', $auction->id)
            ->with(['winner', 'lot', 'fallbackOffers.vendor'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $awards,
        ]);
    }

    public function issueAward(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $validated = $request->validate([
            'winner_vendor_id' => 'required|exists:vendors,id',
            'award_amount' => 'required|numeric|min:0',
            'rank' => 'nullable|string|in:H1,H2,L1,L2',
            'acceptance_hours' => 'nullable|integer|min:1|max:168',
        ]);

        $awardCode = 'AWD-'.now()->year.'-'.str_pad((string) (Award::count() + 1), 4, '0', STR_PAD_LEFT);
        $deadline = now()->addHours($validated['acceptance_hours'] ?? 48);

        $award = Award::create([
            'code' => $awardCode,
            'auction_id' => $auction->id,
            'winner_vendor_id' => $validated['winner_vendor_id'],
            'rank' => $validated['rank'] ?? ($auction->isReverse() ? 'L1' : 'H1'),
            'award_amount' => $validated['award_amount'],
            'status' => 'offered',
            'offered_at' => now(),
            'acceptance_deadline' => $deadline,
        ]);

        $auction->update([
            'status' => 'closed',
            'final_price' => $validated['award_amount'],
            'winner_vendor_id' => $validated['winner_vendor_id'],
            'winner_name' => Vendor::find($validated['winner_vendor_id'])?->company_name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Official award letter issued to winner.',
            'data' => $award,
        ], 201);
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $award = Award::with(['auction', 'result'])->findOrFail($id);
        $user = $request->user();
        $vendor = $user->vendor;

        if (! $vendor || $vendor->id !== $award->winner_vendor_id) {
            return response()->json(['success' => false, 'message' => 'Only the designated award winner can accept.'], 403);
        }

        if ($award->status === 'accepted') {
            return response()->json(['success' => true, 'message' => 'Award was already accepted.', 'data' => ['award' => $award, 'order' => Order::where('auction_id', $award->auction_id)->where('vendor_id', $vendor->id)->latest('id')->first()]]);
        }
        abort_unless($award->status === 'offered', 422, 'This award is no longer available for acceptance.');

        $order = DB::transaction(function () use ($award, $vendor, $user) {
            $award->update(['status' => 'accepted', 'accepted_at' => now()]);
            $result = $award->result ?: $award->auction->result;
            if ($result) {
                WinnerConfirmation::where('result_id', $result->id)->where('participant_id', $vendor->id)->where('rank', $award->rank)->update([
                    'confirmation_status' => 'ACCEPTED', 'responded_at' => now(), 'response' => 'Award accepted',
                ]);
                $this->releaseFallbackHolds($award, $result);
            }

            // Auto-generate Sale / Purchase Order, exactly once for this award.
            $existing = Order::where('auction_id', $award->auction_id)->where('vendor_id', $vendor->id)->latest('id')->first();
            if ($existing) return $existing;
            $gstAmount = round((float) $award->award_amount * 0.18, 2);
            $tcsAmount = round((float) $award->award_amount * 0.01, 2);
            $total = round((float) $award->award_amount + $gstAmount + $tcsAmount, 2);
            return Order::create([
                'code' => 'ORD-'.now()->year.'-'.str_pad((string) (Order::count() + 1), 4, '0', STR_PAD_LEFT),
                'auction_id' => $award->auction_id, 'lot_id' => $award->lot_id, 'vendor_id' => $vendor->id, 'user_id' => $user->id,
                'winning_amount' => $award->award_amount, 'emd_applied' => (float) $award->auction->emd_amount,
                'gst_pct' => 18.00, 'gst_amount' => $gstAmount, 'tcs_pct' => 1.00, 'tcs_amount' => $tcsAmount,
                'total_amount' => $total, 'balance_due' => max(0, $total - (float) $award->auction->emd_amount),
                'status' => 'awaiting_payment', 'payment_due_at' => now()->addDays(2),
            ]);
        });
        AuditLogger::write('WINNER_ACCEPTED', 'award', (string) $award->id, ['auction_id' => $award->auction_id, 'vendor_id' => $vendor->id]);
        app(NotificationService::class)->push($vendor->user, 'WINNER_ACCEPTED', 'Winner acceptance recorded', $award->auction->title, ['auction_code' => $award->auction->code, 'award_id' => $award->id]);

        return response()->json([
            'success' => true,
            'message' => 'Award accepted successfully. Commercial Order generated.',
            'data' => [
                'award' => $award,
                'order' => $order,
            ],
        ]);
    }

    /** Admin/compliance action used when a seller confirms acceptance outside the portal. */
    public function adminAccept(Request $request, int $id): JsonResponse
    {
        $award = Award::with(['auction', 'result'])->findOrFail($id);
        $vendor = Vendor::with('user')->findOrFail($award->winner_vendor_id);
        abort_unless($award->status === 'offered', 422, 'This award is no longer pending acceptance.');

        $order = DB::transaction(function () use ($award, $vendor) {
            $award->update(['status' => 'accepted', 'accepted_at' => now()]);
            $result = $award->result ?: $award->auction->result;
            if ($result) {
                WinnerConfirmation::where('result_id', $result->id)->where('participant_id', $vendor->id)->where('rank', $award->rank)->update(['confirmation_status' => 'ACCEPTED', 'responded_at' => now(), 'response' => 'Admin recorded acceptance']);
                $this->releaseFallbackHolds($award, $result);
            }
            return Order::firstOrCreate(
                ['auction_id' => $award->auction_id, 'vendor_id' => $vendor->id],
                ['code' => 'ORD-'.now()->year.'-'.str_pad((string) (Order::count() + 1), 4, '0', STR_PAD_LEFT), 'lot_id' => $award->lot_id, 'user_id' => $vendor->user_id, 'winning_amount' => $award->award_amount, 'emd_applied' => (float) $award->auction->emd_amount, 'gst_pct' => 18, 'gst_amount' => round((float) $award->award_amount * .18, 2), 'tcs_pct' => 1, 'tcs_amount' => round((float) $award->award_amount * .01, 2), 'total_amount' => round((float) $award->award_amount * 1.19, 2), 'balance_due' => max(0, round((float) $award->award_amount * 1.19, 2) - (float) $award->auction->emd_amount), 'status' => 'awaiting_payment', 'payment_due_at' => now()->addDays(2)]
            );
        });
        AuditLogger::write('WINNER_ACCEPTED_BY_ADMIN', 'award', (string) $award->id, ['auction_id' => $award->auction_id, 'vendor_id' => $vendor->id]);
        app(NotificationService::class)->push($vendor->user, 'WINNER_ACCEPTED', 'Winner acceptance recorded', $award->auction->title, ['auction_code' => $award->auction->code, 'award_id' => $award->id], "award:{$award->id}:accepted");
        return response()->json(['success' => true, 'message' => 'Admin acceptance recorded.', 'data' => ['award' => $award->fresh(), 'order' => $order]]);
    }

    public function decline(Request $request, int $id): JsonResponse
    {
        $award = Award::with('result')->findOrFail($id);
        $vendor = $request->user()?->vendor;
        abort_unless($vendor && $vendor->id === $award->winner_vendor_id, 403, 'Only the designated award winner can decline.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        abort_unless($award->status === 'offered', 422, 'This award is no longer pending confirmation.');

        $award->update(['status' => 'declined', 'decline_reason' => $data['reason']]);
        if ($award->result) {
            WinnerConfirmation::where('result_id', $award->result->id)->where('participant_id', $vendor->id)->where('rank', $award->rank)->update([
                'confirmation_status' => 'DECLINED', 'responded_at' => now(), 'response' => $data['reason'],
            ]);
        }
        AuditLogger::write('WINNER_DECLINED', 'award', (string) $award->id, ['auction_id' => $award->auction_id, 'reason' => $data['reason']]);
        app(NotificationService::class)->push($vendor->user, 'WINNER_DECLINED', 'Winner response recorded', 'Your award decline has been recorded.', ['auction_code' => $award->auction->code, 'award_id' => $award->id]);
        return response()->json(['success' => true, 'message' => 'Winner response recorded.', 'data' => $award->fresh()]);
    }

    public function defaultWinner(Request $request, int $id): JsonResponse
    {
        $award = Award::with(['auction', 'result'])->findOrFail($id);
        $validated = $request->validate([
            'reason' => 'required|string',
            'forfeit_emd' => 'boolean',
        ]);

        abort_unless(in_array($award->status, ['offered', 'declined'], true), 422, 'This award cannot be defaulted in its current state.');
        $result = $award->result ?: $award->auction->result;
        $forfeit = (bool) ($validated['forfeit_emd'] ?? false);
        $policy = $award->auction->frozenConfig('winner_default_policy', 'NO_FORFEITURE');
        if ($forfeit && ! in_array($policy, ['FULL_EMD_FORFEITURE', 'ADMIN_APPROVED'], true)) {
            abort(422, 'The frozen winner-default policy does not permit full EMD forfeiture.');
        }
        $fallbackRank = $award->auction->isReverse() ? 'L2' : 'H2';
        $award->update(['status' => 'defaulted', 'default_reason' => $validated['reason']]);

        if ($result) {
            WinnerConfirmation::where('result_id', $result->id)->where('participant_id', $award->winner_vendor_id)->where('rank', $award->rank)->update([
                'confirmation_status' => 'DEFAULTED', 'responded_at' => now(), 'default_reason' => $validated['reason'],
            ]);
            if ($forfeit) {
                $emd = $award->auction->emdTransactions()->where('vendor_id', $award->winner_vendor_id)->where('status', 'locked')->first();
                if ($emd) {
                    app(EmdService::class)->forfeit($emd, 'Winner defaulted: '.$validated['reason']);
                    SettlementLedgerEntry::firstOrCreate(['idempotency_key' => "result:{$result->id}:emd:{$emd->id}:FORFEITURE"], [
                        'auction_id' => $award->auction_id, 'vendor_id' => $emd->vendor_id, 'emd_id' => $emd->id, 'result_id' => $result->id,
                        'config_snapshot_id' => $result->config_snapshot_id, 'terms_version_id' => $result->terms_version_id,
                        'operation_type' => 'FORFEITURE', 'amount' => $emd->amount, 'reason' => $validated['reason'], 'status' => 'applied',
                    ]);
                }
            }
        }

        // The fallback must come from the immutable close-time ranking, not a later bid query.
        $fallbackVendorId = $result?->second_rank_vendor_id;
        $fallbackBid = $result?->second_rank_bid_id ? $award->auction->bids()->whereKey($result->second_rank_bid_id)->first() : null;
        if (! $fallbackVendorId) {
            $fallbackBid = $award->auction->bids()->where('vendor_id', '!=', $award->winner_vendor_id)->when($award->auction->isReverse(), fn ($q) => $q->orderBy('amount', 'asc'), fn ($q) => $q->orderBy('amount', 'desc'))->first();
            $fallbackVendorId = $fallbackBid?->vendor_id;
        }

        $fallbackOffer = null;
        if ((bool) $award->auction->frozenConfig('fallback_allowed', true) && $fallbackVendorId && $fallbackBid) {
            $policy = $award->auction->frozenConfig('fallback_price_policy', 'SECOND_RANK_OWN_VALUE');
            $offerAmount = $policy === 'MATCH_PRIMARY_WINNER_VALUE' ? $award->award_amount : $fallbackBid->amount;
            $fallbackOffer = FallbackOffer::create([
                'award_id' => $award->id,
                'vendor_id' => $fallbackVendorId, 'rank' => $fallbackRank,
                'offer_amount' => $offerAmount, 'price_delta' => abs($offerAmount - $award->award_amount),
                'expires_at' => now()->addDays(2),
                'status' => 'offered',
                'notes' => 'Fallback offered from frozen '.$fallbackRank.' ranking. Policy: '.$policy.'. '.$validated['reason'],
            ]);
            if ($result) WinnerConfirmation::firstOrCreate([
                'auction_id' => $award->auction_id, 'result_id' => $result->id, 'participant_id' => $fallbackVendorId, 'rank' => $fallbackRank,
            ], ['offered_value' => $offerAmount, 'confirmation_status' => 'CONFIRMATION_PENDING', 'response_deadline' => now()->addDays(2), 'notified_at' => now()]);
        }

        AuditLogger::write('WINNER_DEFAULTED', 'award', (string) $award->id, ['auction_id' => $award->auction_id, 'forfeit_emd' => $forfeit, 'fallback_vendor_id' => $fallbackVendorId]);
        if ($fallbackVendorId) app(NotificationService::class)->push(Vendor::find($fallbackVendorId)?->user, 'FALLBACK_OFFER', 'Fallback offer available', $award->auction->title, ['auction_code' => $award->auction->code, 'fallback_offer_id' => $fallbackOffer?->id]);

        return response()->json([
            'success' => true,
            'message' => 'Winner default recorded. EMD marked for forfeiture.',
            'data' => [
                'award' => $award,
                'fallback_offer' => $fallbackOffer,
            ],
        ]);
    }

    public function acceptFallback(Request $request, int $id): JsonResponse
    {
        $offer = FallbackOffer::with(['award.auction', 'award.result'])->findOrFail($id);
        $vendor = $request->user()?->vendor;
        abort_unless($vendor && $vendor->id === $offer->vendor_id, 403, 'Only the designated fallback participant can accept.');
        abort_unless($offer->status === 'offered' && (! $offer->expires_at || $offer->expires_at->isFuture()), 422, 'This fallback offer is no longer available.');

        return DB::transaction(function () use ($offer, $vendor) {
            $offer->update(['status' => 'accepted']);
            $award = $offer->award;
            $result = $award->result ?: $award->auction->result;
            $fallbackAward = Award::firstOrCreate(
                ['auction_id' => $award->auction_id, 'result_id' => $result?->id, 'winner_vendor_id' => $vendor->id, 'rank' => $offer->rank],
                ['code' => 'AWD-'.now()->year.'-'.str_pad((string) (Award::count() + 1), 4, '0', STR_PAD_LEFT), 'award_amount' => $offer->offer_amount, 'status' => 'accepted', 'offered_at' => now(), 'accepted_at' => now(), 'acceptance_deadline' => now()],
            );
            $offer->award->auction->update(['winner_vendor_id' => $vendor->id, 'winner_name' => $vendor->company_name, 'final_price' => $offer->offer_amount, 'status' => 'closed']);
            if ($result) {
                $result->update(['status' => 'fallback_confirmed']);
                WinnerConfirmation::where('result_id', $result->id)->where('participant_id', $vendor->id)->where('rank', $offer->rank)->update([
                    'confirmation_status' => 'ACCEPTED', 'responded_at' => now(), 'response' => 'Fallback accepted',
                ]);
                AuditLogger::write('FALLBACK_ACCEPTED', 'fallback_offer', (string) $offer->id, ['auction_id' => $award->auction_id, 'vendor_id' => $vendor->id, 'award_id' => $fallbackAward->id]);
                app(NotificationService::class)->push($vendor->user, 'FALLBACK_ACCEPTED', 'Fallback offer accepted', $award->auction->title, ['auction_code' => $award->auction->code, 'offer_id' => $offer->id]);
            }
            return response()->json(['success' => true, 'message' => 'Fallback offer accepted.', 'data' => ['offer' => $offer->fresh(), 'award' => $fallbackAward->fresh()]]);
        });
    }

    public function declineFallback(Request $request, int $id): JsonResponse
    {
        $offer = FallbackOffer::with('award.result')->findOrFail($id);
        $vendor = $request->user()?->vendor;
        abort_unless($vendor && $vendor->id === $offer->vendor_id, 403, 'Only the designated fallback participant can decline.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        abort_unless($offer->status === 'offered', 422, 'This fallback offer is no longer pending.');
        $offer->update(['status' => 'declined', 'notes' => trim(($offer->notes ? $offer->notes.' ' : '').'Declined: '.$data['reason'])]);
        if ($offer->award->result) WinnerConfirmation::where('result_id', $offer->award->result->id)->where('participant_id', $vendor->id)->where('rank', $offer->rank)->update([
            'confirmation_status' => 'DECLINED', 'responded_at' => now(), 'response' => $data['reason'],
        ]);
        $result = $offer->award->result;
        if ($result) {
            $policy = $offer->award->auction->frozenConfig('fallback_exhaustion_policy', 'ADMIN_MANUAL_DECISION');
            $next = null;
            if ($policy === 'NEXT_RANK_ALLOWED') {
                $currentIndex = collect($result->ranking_snapshot)->search(fn ($row) => $row['rank'] === $offer->rank);
                $next = $currentIndex === false ? null : collect($result->ranking_snapshot)->get($currentIndex + 1);
            }
            if ($next) {
                $nextOffer = FallbackOffer::create(['award_id' => $offer->award_id, 'vendor_id' => $next['vendor_id'], 'rank' => $next['rank'], 'offer_amount' => $next['amount'], 'price_delta' => abs((float) $next['amount'] - (float) $offer->award->award_amount), 'expires_at' => now()->addDays(2), 'status' => 'offered', 'notes' => 'Next-rank fallback allowed by frozen policy after '.$offer->rank.' declined.']);
                WinnerConfirmation::firstOrCreate(['auction_id' => $offer->award->auction_id, 'result_id' => $result->id, 'participant_id' => $next['vendor_id'], 'rank' => $next['rank']], ['offered_value' => $next['amount'], 'confirmation_status' => 'CONFIRMATION_PENDING', 'response_deadline' => now()->addDays(2), 'notified_at' => now()]);
                AuditLogger::write('FALLBACK_STARTED', 'fallback_offer', (string) $nextOffer->id, ['auction_id' => $offer->award->auction_id, 'previous_offer_id' => $offer->id, 'policy' => $policy]);
            } else {
                $result->update(['status' => 'FALLBACK_EXHAUSTED_REVIEW_REQUIRED']);
                AuditLogger::write('FALLBACK_EXHAUSTED', 'auction_result', (string) $result->id, ['auction_id' => $offer->award->auction_id, 'policy' => $policy, 'last_rank' => $offer->rank]);
            }
        }
        AuditLogger::write('FALLBACK_DECLINED', 'fallback_offer', (string) $offer->id, ['auction_id' => $offer->award->auction_id, 'vendor_id' => $vendor->id]);
        app(NotificationService::class)->push($vendor->user, 'FALLBACK_DECLINED', 'Fallback response recorded', $offer->award->auction->title, ['auction_code' => $offer->award->auction->code, 'offer_id' => $offer->id]);
        return response()->json(['success' => true, 'message' => 'Fallback response recorded.', 'data' => $offer->fresh()]);
    }

    private function releaseFallbackHolds(Award $award, AuctionResult $result): void
    {
        $fallbackId = $result->second_rank_vendor_id;
        if (! $fallbackId) return;
        $emd = $award->auction->emdTransactions()->where('vendor_id', $fallbackId)->where('status', 'locked')->first();
        if (! $emd) return;
        app(EmdService::class)->release($emd, 'Primary winner accepted; fallback EMD released');
        SettlementLedgerEntry::firstOrCreate(['idempotency_key' => "result:{$result->id}:emd:{$emd->id}:EMD_RELEASE_FALLBACK"], [
            'auction_id' => $award->auction_id, 'vendor_id' => $emd->vendor_id, 'emd_id' => $emd->id, 'result_id' => $result->id,
            'config_snapshot_id' => $result->config_snapshot_id, 'terms_version_id' => $result->terms_version_id,
            'operation_type' => 'EMD_RELEASE_FALLBACK', 'amount' => $emd->amount, 'reason' => 'Primary winner accepted', 'status' => 'applied',
        ]);
    }
}
