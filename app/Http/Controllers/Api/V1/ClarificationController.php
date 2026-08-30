<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AddendumAcknowledgement;
use App\Models\Auction;
use App\Models\AuctionAddendum;
use App\Models\Clarification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClarificationController extends Controller
{
    public function index(string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $clarifications = Clarification::where('auction_id', $auction->id)
            ->with(['vendor', 'answeredBy'])
            ->orderByDesc('created_at')
            ->get();

        $addenda = AuctionAddendum::where('auction_id', $auction->id)
            ->with(['publisher', 'acknowledgements'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'clarifications' => $clarifications,
                'addenda' => $addenda,
            ],
        ]);
    }

    public function askQuestion(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $user = $request->user();
        $vendor = $user->vendor;

        if (! $vendor) {
            return response()->json(['success' => false, 'message' => 'Vendor profile required to submit queries.'], 403);
        }

        $validated = $request->validate([
            'question' => 'required|string|max:1000',
            'section' => 'nullable|string|max:120',
            'is_public' => 'boolean',
        ]);

        $query = Clarification::create([
            'auction_id' => $auction->id,
            'vendor_id' => $vendor->id,
            'user_id' => $user->id,
            'question' => $validated['question'],
            'section' => $validated['section'] ?? 'Commercial Terms',
            'is_public' => $validated['is_public'] ?? true,
            'status' => 'open',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Clarification question submitted for event owner review.',
            'data' => $query,
        ], 201);
    }

    public function answerQuestion(Request $request, string $code, int $id): JsonResponse
    {
        $clarification = Clarification::findOrFail($id);
        $user = $request->user();

        $validated = $request->validate([
            'answer' => 'required|string',
            'is_public' => 'boolean',
        ]);

        $clarification->update([
            'answer' => $validated['answer'],
            'is_public' => $validated['is_public'] ?? true,
            'answered_by' => $user->id,
            'answered_at' => now(),
            'status' => 'answered',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Clarification answered and published.',
            'data' => $clarification,
        ]);
    }

    public function publishAddendum(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $user = $request->user();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'document_url' => 'nullable|string',
        ]);

        $version = AuctionAddendum::where('auction_id', $auction->id)->count() + 1;
        $addendumNumber = 'Addendum-'.str_pad((string) $version, 2, '0', STR_PAD_LEFT);

        $addendum = AuctionAddendum::create([
            'auction_id' => $auction->id,
            'addendum_number' => $addendumNumber,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'document_url' => $validated['document_url'] ?? null,
            'version' => $version,
            'published_by' => $user->id,
            'published_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Official addendum published. Participant acknowledgement required.',
            'data' => $addendum,
        ], 201);
    }

    public function acknowledgeAddendum(Request $request, string $code, int $addendumId): JsonResponse
    {
        $addendum = AuctionAddendum::findOrFail($addendumId);
        $user = $request->user();
        $vendor = $user->vendor;

        if (! $vendor) {
            return response()->json(['success' => false, 'message' => 'Vendor profile required to acknowledge addenda.'], 403);
        }

        $ack = AddendumAcknowledgement::updateOrCreate(
            ['auction_addendum_id' => $addendum->id, 'vendor_id' => $vendor->id],
            ['user_id' => $user->id, 'acknowledged_at' => now()]
        );

        return response()->json([
            'success' => true,
            'message' => 'Addendum acknowledged.',
            'data' => $ack,
        ]);
    }
}
