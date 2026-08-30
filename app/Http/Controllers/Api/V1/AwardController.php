<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\Award;
use App\Models\FallbackOffer;
use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $award = Award::findOrFail($id);
        $user = $request->user();
        $vendor = $user->vendor;

        if (! $vendor || $vendor->id !== $award->winner_vendor_id) {
            return response()->json(['success' => false, 'message' => 'Only the designated award winner can accept.'], 403);
        }

        $award->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        // Auto-generate Sale / Purchase Order
        $orderCode = 'ORD-'.now()->year.'-'.str_pad((string) (Order::count() + 1), 4, '0', STR_PAD_LEFT);
        $gstAmount = $award->award_amount * 0.18;
        $tcsAmount = $award->award_amount * 0.01;
        $total = $award->award_amount + $gstAmount + $tcsAmount;
        $emdApplied = (float) $award->auction->emd_amount;

        $order = Order::create([
            'code' => $orderCode,
            'auction_id' => $award->auction_id,
            'lot_id' => $award->lot_id,
            'vendor_id' => $vendor->id,
            'user_id' => $user->id,
            'winning_amount' => $award->award_amount,
            'emd_applied' => $emdApplied,
            'gst_pct' => 18.00,
            'gst_amount' => $gstAmount,
            'tcs_pct' => 1.00,
            'tcs_amount' => $tcsAmount,
            'total_amount' => $total,
            'balance_due' => max(0, $total - $emdApplied),
            'status' => 'awaiting_payment',
            'payment_due_at' => now()->addDays(2),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Award accepted successfully. Commercial Order generated.',
            'data' => [
                'award' => $award,
                'order' => $order,
            ],
        ]);
    }

    public function defaultWinner(Request $request, int $id): JsonResponse
    {
        $award = Award::findOrFail($id);
        $validated = $request->validate([
            'reason' => 'required|string',
            'forfeit_emd' => 'boolean',
        ]);

        $award->update([
            'status' => 'defaulted',
            'default_reason' => $validated['reason'],
        ]);

        // Check if there is H2 / L2 for fallback offer
        $fallbackVendor = $award->auction->bids()
            ->where('vendor_id', '!=', $award->winner_vendor_id)
            ->when($award->auction->isReverse(), fn ($q) => $q->orderBy('amount', 'asc'), fn ($q) => $q->orderBy('amount', 'desc'))
            ->first();

        $fallbackOffer = null;
        if ($fallbackVendor) {
            $fallbackOffer = FallbackOffer::create([
                'award_id' => $award->id,
                'vendor_id' => $fallbackVendor->vendor_id,
                'rank' => $award->auction->isReverse() ? 'L2' : 'H2',
                'offer_amount' => $fallbackVendor->amount,
                'price_delta' => abs($fallbackVendor->amount - $award->award_amount),
                'expires_at' => now()->addDays(2),
                'status' => 'offered',
                'notes' => 'Fallback offered due to H1/L1 default: '.$validated['reason'],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Winner default recorded. EMD marked for forfeiture.',
            'data' => [
                'award' => $award,
                'fallback_offer' => $fallbackOffer,
            ],
        ]);
    }
}
