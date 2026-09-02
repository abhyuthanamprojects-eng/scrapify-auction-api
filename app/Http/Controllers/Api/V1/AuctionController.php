<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\AuctionStateChanged;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuctionResource;
use App\Models\Auction;
use App\Models\AuctionExtension;
use App\Models\AuctionTermsAcceptance;
use App\Models\Category;
use App\Models\InterestedBidder;
use App\Models\Lot;
use App\Services\EmdService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AuctionController extends Controller
{
    public function __construct(private EmdService $emd, private NotificationService $notifications)
    {
    }

    /**
     * One listing endpoint for all three consumers. Anonymous callers see only
     * published/live/closed auctions; staff see everything and can filter by
     * the approval statuses the admin queue needs.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $isStaff = $user && $user->hasPermission('auctions.approve');

        $q = Auction::query()
            ->with(['category', 'photos', 'lots'])
            ->withCount('interestedBidders');

        if (! $isStaff) {
            $q->public();
        }

        if ($status = $request->query('status')) {
            $q->whereIn('status', array_map('trim', explode(',', $status)));
        }

        if ($category = $request->query('category')) {
            $q->whereHas('category', fn ($c) => $c->where('slug', $category)->orWhere('name', $category));
        }

        if ($company = $request->query('company')) {
            $q->where('company', $company);
        }

        if ($direction = $request->query('direction')) {
            $q->where('direction', $direction);
        }

        if ($search = $request->query('search')) {
            $q->where(fn ($w) => $w->where('code', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%")
                ->orWhere('company', 'like', "%{$search}%"));
        }

        if ($from = $request->query('from')) {
            $q->where('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $q->where('created_at', '<=', $to);
        }

        // Mobile "Live / Upcoming / Ended" segments.
        match ($request->query('segment')) {
            'live' => $q->where('status', 'live'),
            'upcoming' => $q->whereIn('status', ['published', 'approved'])->where('schedule_start', '>', now()),
            'ended' => $q->whereIn('status', ['closed', 'cancelled']),
            default => null,
        };

        $q->orderByDesc('created_at');

        return AuctionResource::collection($q->paginate((int) $request->query('per_page', 25)));
    }

    public function show(string $code): AuctionResource
    {
        $auction = Auction::where('code', $code)
            ->with(['category', 'photos', 'lots', 'extensions', 'bids' => fn ($b) => $b->latest('id')->limit(50)])
            ->withCount('interestedBidders')
            ->firstOrFail();

        return new AuctionResource($auction);
    }

    /**
     * Create — mirrors the four steps of the Scrap Auction Creation flow:
     * identification, lot preparation, inspection, auction details.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $user = $request->user();

        $auction = Auction::create(array_merge($this->attributes($data), [
            'status' => $data['status'] ?? 'draft',
            'submitted_by' => $user->id,
            'submitted_by_name' => $user->name,
        ]));

        $this->syncLots($auction, $data['sub_lots'] ?? []);
        $this->syncPhotos($auction, $data['photos'] ?? []);

        return (new AuctionResource($auction->load(['category', 'lots', 'photos'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, string $code): AuctionResource
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        abort_if(
            in_array($auction->status, ['closed', 'cancelled'], true),
            422,
            'A closed or cancelled auction cannot be edited.',
        );

        $data = $this->validated($request, partial: true);
        $auction->update($this->attributes($data, partial: true));

        if (array_key_exists('sub_lots', $data)) {
            $auction->lots()->delete();
            $this->syncLots($auction, $data['sub_lots']);
        }

        if (array_key_exists('photos', $data)) {
            $auction->photos()->delete();
            $this->syncPhotos($auction, $data['photos']);
        }

        return new AuctionResource($auction->fresh(['category', 'lots', 'photos']));
    }

    public function submit(string $code): AuctionResource
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        abort_unless(
            in_array($auction->status, ['draft', 'sent_back'], true),
            422,
            "Only a draft or sent-back auction can be submitted (status: {$auction->status}).",
        );

        $auction->update(['status' => 'pending_approval', 'submitted_at' => now()]);

        return new AuctionResource($auction);
    }

    public function approve(Request $request, string $code): AuctionResource
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        abort_unless($auction->status === 'pending_approval', 422, 'This auction is not awaiting approval.');

        $auction->update([
            'status' => 'approved',
            'review_comment' => null,
            'reviewed_by' => $request->user()->id,
        ]);

        broadcast(new AuctionStateChanged($auction, 'approved'));

        return new AuctionResource($auction);
    }

    public function sendBack(Request $request, string $code): AuctionResource
    {
        $data = $request->validate(['comment' => ['required', 'string', 'max:2000']]);
        $auction = Auction::where('code', $code)->firstOrFail();

        $auction->update([
            'status' => 'sent_back',
            'review_comment' => $data['comment'],
            'reviewed_by' => $request->user()->id,
        ]);

        return new AuctionResource($auction);
    }

    public function reject(Request $request, string $code): AuctionResource
    {
        $data = $request->validate(['comment' => ['required', 'string', 'max:2000']]);
        $auction = Auction::where('code', $code)->firstOrFail();

        $auction->update([
            'status' => 'rejected',
            'review_comment' => $data['comment'],
            'reviewed_by' => $request->user()->id,
        ]);

        return new AuctionResource($auction);
    }

    public function publish(Request $request, string $code): AuctionResource
    {
        $data = $request->validate([
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['string', 'max:60'],
        ]);

        $auction = Auction::where('code', $code)->firstOrFail();

        abort_unless($auction->status === 'approved', 422, 'Only an approved auction can be published.');

        $auction->update([
            'status' => 'published',
            'published_at' => now(),
            'publish_channels' => $data['channels'] ?? ['Web Portal', 'Mobile App', 'Email'],
        ]);

        $this->notifications->auctionPublished($auction);
        broadcast(new AuctionStateChanged($auction, 'published'));

        return new AuctionResource($auction);
    }

    /** Flip a published auction into live bidding. */
    public function golive(string $code): AuctionResource
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        abort_unless(
            in_array($auction->status, ['published', 'approved'], true),
            422,
            'Only a published auction can go live.',
        );

        $auction->update(['status' => 'live']);
        broadcast(new AuctionStateChanged($auction, 'live'));

        return new AuctionResource($auction);
    }

    public function extend(Request $request, string $code): AuctionResource
    {
        $data = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $auction = Auction::where('code', $code)->firstOrFail();

        abort_unless($auction->status === 'live', 422, 'Only a live auction can be extended.');

        $auction->update([
            'schedule_end' => $auction->schedule_end?->addMinutes($data['minutes']) ?? now()->addMinutes($data['minutes']),
        ]);

        AuctionExtension::create([
            'auction_id' => $auction->id,
            'user_id' => $request->user()->id,
            'minutes' => $data['minutes'],
            'reason' => $data['reason'],
        ]);

        broadcast(new AuctionStateChanged($auction, 'extended'));

        return new AuctionResource($auction->fresh('extensions'));
    }

    /**
     * Close an auction: settle the winner, release every losing EMD hold,
     * and tell the room.
     */
    public function close(Request $request, string $code): AuctionResource
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        abort_if(
            in_array($auction->status, ['closed', 'cancelled'], true),
            422,
            'This auction is already closed.',
        );

        $winning = $auction->bids()
            ->orderBy('amount', $auction->isReverse() ? 'asc' : 'desc')
            ->orderByDesc('id')
            ->first();

        $auction->update([
            'status' => 'closed',
            'closed_at' => now(),
            'final_price' => $winning?->amount ?? $auction->current_highest,
            'winner_vendor_id' => $winning?->vendor_id,
            'winner_name' => $winning?->vendor_name,
        ]);

        foreach ($auction->lots as $lot) {
            $lotWinner = $lot->bids()
                ->orderBy('amount', $auction->isReverse() ? 'asc' : 'desc')
                ->orderByDesc('id')
                ->first();

            $lot->update([
                'status' => 'closed',
                'final_price' => $lotWinner?->amount,
                'winner_vendor_id' => $lotWinner?->vendor_id,
            ]);
        }

        $this->emd->releaseLosers($auction);
        $this->notifications->auctionClosed($auction);
        broadcast(new AuctionStateChanged($auction, 'closed'));

        return new AuctionResource($auction->fresh(['lots']));
    }

    public function cancel(Request $request, string $code): AuctionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $auction = Auction::where('code', $code)->firstOrFail();

        $auction->update([
            'status' => 'cancelled',
            'closed_at' => now(),
            'review_comment' => $data['reason'],
        ]);

        $this->emd->releaseLosers($auction);
        broadcast(new AuctionStateChanged($auction, 'cancelled'));

        return new AuctionResource($auction);
    }

    /**
     * "Interested" click. Deliberately open to anonymous visitors — the public
     * web listing offers it before any registration exists.
     */
    public function markInterested(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['anon_key' => ['nullable', 'string', 'max:64']]);
        $auction = Auction::where('code', $code)->firstOrFail();
        $user = $request->user();

        abort_if(! $user && empty($data['anon_key']), 422, 'anon_key is required for anonymous interest.');

        InterestedBidder::updateOrCreate(
            $user
                ? ['auction_id' => $auction->id, 'user_id' => $user->id]
                : ['auction_id' => $auction->id, 'anon_key' => $data['anon_key']],
            ['ip' => $request->ip()],
        );

        return response()->json([
            'interested' => true,
            'interested_count' => $auction->interestedBidders()->count(),
        ], 201);
    }

    public function unmarkInterested(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $user = $request->user();

        InterestedBidder::where('auction_id', $auction->id)
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->when(! $user, fn ($q) => $q->where('anon_key', $request->query('anon_key')))
            ->delete();

        return response()->json([
            'interested' => false,
            'interested_count' => $auction->interestedBidders()->count(),
        ]);
    }

    public function acceptTerms(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $user = $request->user();

        AuctionTermsAcceptance::updateOrCreate(
            ['auction_id' => $auction->id, 'user_id' => $user->id],
            ['ip' => $request->ip(), 'accepted_at' => now()],
        );

        return response()->json([
            'accepted' => true,
            'auction_id' => $auction->code,
            'accepted_at' => now()->toIso8601String(),
        ]);
    }

    /** Lightweight polling fallback for clients not on the websocket. */
    public function liveState(string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->with('lots')->firstOrFail();

        return response()->json([
            'code' => $auction->code,
            'status' => $auction->status,
            'direction' => $auction->direction,
            'current_highest_inr' => $auction->current_highest !== null ? (float) $auction->current_highest : null,
            'bid_increment_inr' => (float) $auction->bid_increment,
            'bidders' => $auction->bidders_count,
            'schedule_end' => $auction->schedule_end?->toIso8601String(),
            'seconds_remaining' => $auction->schedule_end
                ? max(0, now()->diffInSeconds($auction->schedule_end, false))
                : null,
            'lots' => $auction->lots->map(fn ($l) => [
                'id' => $l->code,
                'current_bid_inr' => $l->current_bid !== null ? (float) $l->current_bid : null,
                'bidders' => $l->bidders_count,
            ]),
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$r, 'string', 'max:200'],
            'company' => [$r, 'string', 'max:180'],
            'organization_code' => ['sometimes', 'nullable', 'string', 'exists:organizations,code'],
            'plant' => ['sometimes', 'nullable', 'string', 'max:180'],
            'warehouse' => ['sometimes', 'nullable', 'string', 'max:180'],
            'location' => ['sometimes', 'nullable', 'string', 'max:180'],
            'category' => ['sometimes', 'nullable', 'string'],
            'lot_type' => ['sometimes', Rule::in(['single', 'lot_wise'])],
            'direction' => ['sometimes', Rule::in(['forward', 'reverse'])],
            'material_type' => ['sometimes', 'nullable', 'string', 'max:180'],
            'quantity' => ['sometimes', 'nullable', 'string', 'max:60'],
            'uom' => ['sometimes', Rule::in(['MT', 'KG', 'Nos.'])],
            'reserve_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'reserve_na' => ['sometimes', 'boolean'],
            'starting_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'bid_increment' => ['sometimes', 'numeric', 'min:0'],
            'emd_amount' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'pending_approval'])],
            'schedule_start' => ['sometimes', 'nullable', 'date'],
            'schedule_end' => ['sometimes', 'nullable', 'date', 'after:schedule_start'],
            'inspection' => ['sometimes', 'nullable', 'string'],
            'inspection_date' => ['sometimes', 'nullable', 'string', 'max:60'],
            'inspection_time' => ['sometimes', 'nullable', 'string', 'max:60'],
            'inspection_location' => ['sometimes', 'nullable', 'string', 'max:180'],
            'guidelines_doc' => ['sometimes', 'nullable', 'string', 'max:255'],
            'terms' => ['sometimes', 'nullable', 'string'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lifting_period' => ['sometimes', 'nullable', 'string', 'max:30'],
            'lifting_unit' => ['sometimes', Rule::in(['Days', 'Weeks'])],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'contact_email' => ['sometimes', 'nullable', 'email'],
            'photos' => ['sometimes', 'array'],
            'photos.*' => ['string'],
            'sub_lots' => ['sometimes', 'array'],
            'sub_lots.*.name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'sub_lots.*.quantity' => ['sometimes', 'nullable', 'string', 'max:60'],
            'sub_lots.*.uom' => ['sometimes', Rule::in(['MT', 'KG', 'Nos.'])],
            'sub_lots.*.reserve_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);
    }

    private function attributes(array $data, bool $partial = false): array
    {
        $attrs = collect($data)->except(['sub_lots', 'photos', 'category', 'organization_code', 'status'])->all();

        if (array_key_exists('category', $data) && $data['category']) {
            $attrs['category_id'] = Category::where('slug', $data['category'])
                ->orWhere('name', $data['category'])
                ->value('id');
        }

        if (array_key_exists('organization_code', $data) && $data['organization_code']) {
            $attrs['organization_id'] = \App\Models\Organization::where('code', $data['organization_code'])->value('id');
        }

        if ($partial && array_key_exists('status', $data)) {
            $attrs['status'] = $data['status'];
        }

        return $attrs;
    }

    private function syncLots(Auction $auction, array $lots): void
    {
        foreach (array_values($lots) as $i => $lot) {
            Lot::create([
                'code' => sprintf('%s-L%d', $auction->code, $i + 1),
                'auction_id' => $auction->id,
                'name' => $lot['name'] ?? sprintf('Lot %d', $i + 1),
                'quantity' => $lot['quantity'] ?? null,
                'uom' => $lot['uom'] ?? $auction->uom,
                'reserve_price' => $lot['reserve_price'] ?? null,
            ]);
        }
    }

    private function syncPhotos(Auction $auction, array $photos): void
    {
        foreach (array_values($photos) as $i => $url) {
            $auction->photos()->create(['url' => $url, 'sort_order' => $i]);
        }
    }
}
