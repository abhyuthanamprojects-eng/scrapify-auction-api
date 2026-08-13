<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BidResource;
use App\Models\Auction;
use App\Models\Lot;
use App\Services\BiddingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BidController extends Controller
{
    public function __construct(private BiddingService $bidding)
    {
    }

    public function index(Request $request, string $code): AnonymousResourceCollection
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        $q = $auction->bids()->with(['vendor', 'lot'])->latest('id');

        if ($lotCode = $request->query('lot')) {
            $q->whereHas('lot', fn ($l) => $l->where('code', $lotCode));
        }

        return BidResource::collection($q->paginate((int) $request->query('per_page', 50)));
    }

    public function store(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'lot' => ['sometimes', 'nullable', 'string', 'exists:lots,code'],
        ]);

        $auction = Auction::where('code', $code)->firstOrFail();
        $user = $request->user();
        $vendor = $user->vendor;

        abort_unless($vendor, 422, 'This account has no vendor profile, so it cannot bid.');

        $lotId = isset($data['lot']) && $data['lot']
            ? Lot::where('code', $data['lot'])->where('auction_id', $auction->id)->value('id')
            : null;

        $bid = $this->bidding->place(
            auction: $auction,
            vendor: $vendor,
            user: $user,
            amount: (float) $data['amount'],
            lotId: $lotId,
            ip: $request->ip(),
        );

        return response()->json([
            'bid' => new BidResource($bid->load(['auction', 'lot', 'vendor'])),
            'auction' => [
                'code' => $auction->fresh()->code,
                'current_highest_inr' => (float) $auction->fresh()->current_highest,
                'bidders' => $auction->fresh()->bidders_count,
            ],
        ], 201);
    }

    /** Auto-bid: a ceiling for forward auctions, a floor for reverse tenders. */
    public function setProxy(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'max_amount' => ['required', 'numeric', 'min:0'],
            'lot' => ['sometimes', 'nullable', 'string', 'exists:lots,code'],
        ]);

        $auction = Auction::where('code', $code)->firstOrFail();
        $user = $request->user();

        abort_unless($user->vendor, 422, 'This account has no vendor profile.');

        $proxy = $this->bidding->setProxy(
            $auction,
            $user->vendor,
            $user,
            (float) $data['max_amount'],
            isset($data['lot']) ? Lot::where('code', $data['lot'])->value('id') : null,
        );

        return response()->json(['proxy_bid' => $proxy], 201);
    }

    public function cancelProxy(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        $this->bidding->cancelProxy(
            $auction,
            $request->user()->vendor,
            $request->query('lot') ? Lot::where('code', $request->query('lot'))->value('id') : null,
        );

        return response()->json(['message' => 'Auto-bid cancelled.']);
    }

    /** Every bid this vendor has placed, grouped for the "My Bids" screen. */
    public function myBids(Request $request): JsonResponse
    {
        $vendor = $request->user()->vendor;

        abort_unless($vendor, 422, 'This account has no vendor profile.');

        $bids = $vendor->bids()->with(['auction.category', 'lot'])->latest('id')->get();

        $rows = $bids->groupBy('auction_id')->map(function ($group) use ($vendor) {
            $auction = $group->first()->auction;
            $mine = (float) $group->max('amount');
            $leading = $auction->isReverse()
                ? $mine <= (float) $auction->current_highest
                : $mine >= (float) $auction->current_highest;

            return [
                'auction_id' => $auction->code,
                'title' => $auction->title,
                'status' => $auction->status,
                'my_bid_inr' => $mine,
                'current_inr' => (float) $auction->current_highest,
                'bid_count' => $group->count(),
                'result' => match (true) {
                    $auction->status === 'closed' && $auction->winner_vendor_id === $vendor->id => 'Won',
                    $auction->status === 'closed' => 'Lost',
                    $leading => 'Winning',
                    default => 'Outbid',
                },
            ];
        })->values();

        return response()->json([
            'active' => $rows->whereIn('result', ['Winning', 'Outbid'])->values(),
            'won' => $rows->where('result', 'Won')->values(),
            'lost' => $rows->where('result', 'Lost')->values(),
        ]);
    }
}
