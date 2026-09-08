<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LotResource;
use App\Models\Auction;
use App\Models\Lot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use App\Services\GeneralSettings;

class LotController extends Controller
{
    public function index(string $code): AnonymousResourceCollection
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        return LotResource::collection($auction->lots()->orderBy('id')->get());
    }

    public function show(string $code, string $lotCode): LotResource
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        return new LotResource(
            $auction->lots()->where('code', $lotCode)->firstOrFail(),
        );
    }

    public function store(Request $request, string $code): JsonResponse
    {
        $data = $this->rules($request);
        $auction = Auction::where('code', $code)->firstOrFail();

        $this->authorizeOwnerOrStaff($request, $auction);
        $this->assertEditable($auction);

        abort_unless($auction->isLotWise(), 422, 'Lots can only be added to a lot-wise auction.');

        $next = $auction->lots()->count() + 1;

        $lot = $auction->lots()->create(array_merge($data, [
            'code' => sprintf('%s-L%d', $auction->code, $next),
            'uom' => $data['uom'] ?? $auction->uom,
        ]));

        return (new LotResource($lot))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $code, string $lotCode): LotResource
    {
        $data = $this->rules($request, partial: true);
        $auction = Auction::where('code', $code)->firstOrFail();
        $this->authorizeOwnerOrStaff($request, $auction);
        $this->assertEditable($auction);
        $lot = $auction->lots()->where('code', $lotCode)->firstOrFail();

        abort_if($lot->status === 'closed', 422, 'A closed lot cannot be edited.');

        $lot->update($data);

        return new LotResource($lot);
    }

    public function destroy(Request $request, string $code, string $lotCode): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $this->authorizeOwnerOrStaff($request, $auction);
        $this->assertEditable($auction);
        $lot = $auction->lots()->where('code', $lotCode)->firstOrFail();

        abort_if($lot->bids()->exists(), 422, 'A lot that has received bids cannot be deleted.');

        $lot->delete();

        return response()->json(['message' => 'Lot deleted.']);
    }

    private function rules(Request $request, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$r, 'string', 'max:180'],
            'quantity' => ['sometimes', 'nullable', 'string', 'max:60'],
            'uom' => ['sometimes', Rule::in(['MT', 'KG', 'Nos.'])],
            'reserve_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);
    }

    private function authorizeOwnerOrStaff(Request $request, Auction $auction): void
    {
        $user = $request->user();
        abort_unless($user && ($user->hasPermission('auctions.approve') || (int) $auction->submitted_by === (int) $user->id), 403, 'You may only manage your own auction lots.');
    }

    private function assertEditable(Auction $auction): void
    {
        abort_if(in_array($auction->status, ['closed', 'cancelled', 'live'], true), 422, 'This auction is no longer editable.');
        $lockHours = GeneralSettings::int('auction_edit_lock_hours', 3);
        abort_if(
            $auction->schedule_start && now()->greaterThanOrEqualTo($auction->schedule_start->copy()->subHours($lockHours)),
            422,
            "Editing is locked because this auction starts within {$lockHours} hours.",
        );
    }
}
