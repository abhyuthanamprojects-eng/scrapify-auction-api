<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Auction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = ApprovalRequest::with(['auction.category', 'auction.organization', 'assignedTo']);

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        if ($tier = $request->query('tier')) {
            $q->where('tier', $tier);
        }

        $requests = $q->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $requests->items(),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    public function store(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $validated = $request->validate([
            'tier' => 'required|in:L1,L2,L3,Committee',
            'trigger_reason' => 'required|string|max:255',
            'amount' => 'nullable|numeric',
            'assigned_to_user_id' => 'nullable|exists:users,id',
            'comments' => 'nullable|string',
        ]);

        $codeStr = 'APR-'.now()->year.'-'.str_pad((string) (ApprovalRequest::count() + 1), 4, '0', STR_PAD_LEFT);

        $apr = ApprovalRequest::create([
            'code' => $codeStr,
            'auction_id' => $auction->id,
            'tier' => $validated['tier'],
            'trigger_reason' => $validated['trigger_reason'],
            'amount' => $validated['amount'] ?? $auction->current_highest,
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
            'status' => 'pending',
            'comments' => $validated['comments'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Approval workflow triggered.',
            'data' => $apr,
        ], 201);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $apr = ApprovalRequest::findOrFail($id);
        $user = $request->user();

        $validated = $request->validate([
            'decision' => 'required|in:approved,rejected,escalated',
            'comments' => 'nullable|string',
        ]);

        $apr->update([
            'status' => $validated['decision'],
            'comments' => $validated['comments'] ?? $apr->comments,
            'actioned_at' => now(),
        ]);

        if ($validated['decision'] === 'approved') {
            $apr->auction->update(['status' => 'approved']);
        } elseif ($validated['decision'] === 'rejected') {
            $apr->auction->update(['status' => 'rejected']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Approval decision recorded: '.$validated['decision'],
            'data' => $apr,
        ]);
    }
}
