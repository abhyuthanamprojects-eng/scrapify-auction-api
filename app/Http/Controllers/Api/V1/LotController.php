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
        $lot = $auction->lots()->where('code', $lotCode)->firstOrFail();

        abort_if($lot->status === 'closed', 422, 'A closed lot cannot be edited.');

        $lot->update($data);

        return new LotResource($lot);
    }

    public function destroy(string $code, string $lotCode): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
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
}
