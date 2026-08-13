<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuctionResource;
use App\Http\Resources\TokenResource;
use App\Models\AccessToken;
use App\Models\Auction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TokenController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = AccessToken::query()->with('auction');

        if ($auctionCode = $request->query('auction')) {
            $q->whereHas('auction', fn ($a) => $a->where('code', $auctionCode));
        }

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return TokenResource::collection($q->latest('id')->paginate((int) $request->query('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auction_id' => ['required', 'string', 'exists:auctions,code'],
            'type' => ['required', Rule::in(['view_only', 'can_bid'])],
            'expires_at' => ['required', 'date', 'after:now'],
        ]);

        $token = AccessToken::create([
            'token' => AccessToken::makeToken(),
            'auction_id' => Auction::where('code', $data['auction_id'])->value('id'),
            'type' => $data['type'],
            'expires_at' => $data['expires_at'],
            'created_by' => $request->user()->id,
        ]);

        return (new TokenResource($token->load('auction')))->response()->setStatusCode(201);
    }

    public function revoke(string $code): TokenResource
    {
        $token = AccessToken::where('code', $code)->firstOrFail();

        abort_if($token->status === 'revoked', 422, 'This token is already revoked.');

        $token->update(['status' => 'revoked', 'revoked_at' => now()]);

        return new TokenResource($token->load('auction'));
    }

    /**
     * Public: the web token-access page (/join/{token}) calls this to find out
     * whether the link is still good and what it permits.
     */
    public function validateToken(string $token): JsonResponse
    {
        $row = AccessToken::where('token', $token)->with(['auction.category', 'auction.lots'])->first();

        if (! $row) {
            return response()->json(['valid' => false, 'reason' => 'not_found'], 404);
        }

        if (! $row->isUsable()) {
            return response()->json([
                'valid' => false,
                'reason' => $row->effectiveStatus(),
            ], 403);
        }

        return response()->json([
            'valid' => true,
            'type' => $row->type,
            'can_bid' => $row->type === 'can_bid',
            'expires_at' => $row->expires_at->toIso8601String(),
            'auction' => new AuctionResource($row->auction),
        ]);
    }
}
