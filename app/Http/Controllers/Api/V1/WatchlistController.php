<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuctionResource;
use App\Models\Auction;
use App\Models\Lot;
use App\Models\Watchlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WatchlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $auctionIds = $request->user()->watchlist()->pluck('auction_id');

        return response()->json([
            'data' => AuctionResource::collection(
                Auction::whereIn('id', $auctionIds)->with(['category', 'photos', 'lots'])->get(),
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auction_id' => ['required', 'string', 'exists:auctions,code'],
            'lot' => ['sometimes', 'nullable', 'string', 'exists:lots,code'],
        ]);

        $row = Watchlist::firstOrCreate([
            'user_id' => $request->user()->id,
            'auction_id' => Auction::where('code', $data['auction_id'])->value('id'),
            'lot_id' => isset($data['lot']) ? Lot::where('code', $data['lot'])->value('id') : null,
        ]);

        return response()->json(['watchlist' => $row], 201);
    }

    public function destroy(Request $request, string $code): JsonResponse
    {
        Watchlist::where('user_id', $request->user()->id)
            ->whereHas('auction', fn ($a) => $a->where('code', $code))
            ->delete();

        return response()->json(['message' => 'Removed from watchlist.']);
    }
}
