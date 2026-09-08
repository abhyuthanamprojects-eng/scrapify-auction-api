<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\AuctionStateChanged;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuctionResource;
use App\Models\Auction;
use App\Models\AuctionExtension;
use App\Models\AuctionTermsAcceptance;
use App\Models\AuctionTermsVersion;
use App\Models\AuctionSlot;
use App\Models\AuctionConfigSnapshot;
use App\Models\RfqSubmission;
use App\Models\RfqDiscoveryRound;
use App\Models\RfqDiscoverySubmission;
use App\Models\WinnerConfirmation;
use App\Services\RfqExtractionService;
use App\Services\AuctionReadinessService;
use App\Models\Category;
use App\Models\InterestedBidder;
use App\Models\Lot;
use App\Services\EmdService;
use App\Services\GeneralSettings;
use App\Services\NotificationService;
use App\Services\AuctionResultService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
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
            ->with(['organization', 'category', 'photos', 'lots'])
            ->withCount('interestedBidders');

        if (! $isStaff) {
            if ($user && $user->hasRole('seller') && $request->boolean('mine')) {
                $q->where('submitted_by', $user->id);
            } else {
                $q->public();
            }
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

    /** Seller workspace listing: only auctions owned by the authenticated seller. */
    public function myAuctions(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->hasRole('seller'), 403, 'Only sellers can view their auction workspace.');

        return $this->index($request->merge(['mine' => true]));
    }

    public function show(Request $request, string $code): AuctionResource
    {
        $auction = Auction::where('code', $code)
            ->with(['organization', 'category', 'photos', 'lots', 'extensions'])
            ->withCount('interestedBidders')
            ->firstOrFail();

        $user = $request->user();
        $private = $user && ($user->hasPermission('auctions.approve') || $auction->submitted_by === $user->id);
        if ($private) {
            $auction->load(['bids' => fn ($b) => $b->with('vendor')->latest('id')->limit(50)]);
        }

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

        $isStaff = $user->hasPermission('auctions.approve') || $user->hasPermission('auctions.create_any');
        if (! $isStaff && ! $user->hasRole('seller')) {
            abort(403, 'Only sellers or authorized operations staff can create auctions.');
        }

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
        $this->authorizeOwnerOrStaff($request, $auction);

        abort_if(
            in_array($auction->status, ['closed', 'cancelled'], true),
            422,
            'A closed or cancelled auction cannot be edited.',
        );

        abort_if(
            $auction->schedule_start && now()->greaterThanOrEqualTo($auction->schedule_start->copy()->subHours(GeneralSettings::int('auction_edit_lock_hours', 3))),
            422,
            'Editing is locked because this auction starts within 3 hours.',
        );

        $data = $this->validated($request, partial: true);
        if (array_key_exists('schedule_start', $data) && $auction->schedule_start && now()->greaterThanOrEqualTo($auction->schedule_start->copy()->subHours(GeneralSettings::int('auction_edit_lock_hours', 3)))) {
            abort(422, 'The auction start time cannot be changed after the edit lock begins.');
        }
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

    /**
     * Admin-only controlled auction archival. Historical auctions are never
     * hard-deleted because bids, EMD, terms, RFQ and settlement records must
     * remain available for audit and legal retention.
     */
    public function adminDestroy(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $auction = Auction::where('code', $code)->firstOrFail();
        $oldStatus = (string) $auction->status;

        abort_if(
            in_array($oldStatus, ['live', 'closed', 'completed', 'settled', 'awarded', 'cancelled'], true),
            422,
            'This auction is historical or active and cannot be archived through delete.',
        );

        $hasExecutionHistory = $auction->bids()->exists()
            || $auction->emdTransactions()->exists()
            || $auction->result()->exists()
            || $auction->awards()->exists()
            || $auction->termsAcceptances()->exists()
            || $auction->rfxPackages()->exists();

        abort_if(
            $hasExecutionHistory,
            422,
            'This auction has execution or financial history; use the controlled cancellation and settlement workflow.',
        );

        $auction->update(['status' => 'cancelled']);

        AuditLogger::write(
            'AUCTION_ARCHIVED_BY_ADMIN',
            'auction',
            $auction->code,
            [
                'old_status' => $oldStatus,
                'new_status' => 'cancelled',
                'reason' => $data['reason'],
                'preserved_history' => true,
            ],
            $request->user(),
        );

        return response()->json([
            'message' => 'Auction archived and retained for audit.',
            'data' => [
                'code' => $auction->code,
                'status' => $auction->status,
                'archived' => true,
            ],
        ]);
    }

    public function submit(string $code): AuctionResource
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        abort_unless(auth()->user()->hasPermission('auctions.approve') || $auction->submitted_by === auth()->id(), 403, 'You may only submit your own auction.');

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

    public function updateConfiguration(Request $request, string $code): AuctionResource
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $this->authorizeOwnerOrStaff($request, $auction);
        abort_unless(in_array($auction->status, ['draft', 'pending_approval', 'sent_back', 'approved'], true), 422, 'Auction configuration is locked after publication.');
        $data = $request->validate([
            'rfq_required' => ['sometimes', 'boolean'], 'rfq_mode' => ['sometimes', 'in:DOCUMENT,DISCOVERY_ROUND,HYBRID'],
            'rfq_benchmark_strategy' => ['sometimes', 'in:HIGHEST_VALID,LOWEST_VALID,AVERAGE,MEDIAN,DOCUMENT_APPROVED,DISCOVERY_RESULT,ADMIN_APPROVED'],
            'emd_required' => ['sometimes', 'boolean'], 'emd_type' => ['sometimes', 'in:PERCENTAGE,FIXED'],
            'emd_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'], 'emd_fixed_amount' => ['sometimes', 'numeric', 'min:0'],
            'minimum_participants' => ['sometimes', 'integer', 'min:1', 'max:1000'], 'initial_slot_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
            'continuation_slot_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'], 'bid_cutoff_ms' => ['sometimes', 'integer', 'min:0', 'max:60000'],
            'maximum_auction_duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:10080'], 'auction_edit_lock_hours' => ['sometimes', 'integer', 'min:0', 'max:168'],
            'continuation_mode' => ['sometimes', 'in:MANUAL_ADMIN,AUTOMATIC_POLICY'], 'fallback_allowed' => ['sometimes', 'boolean'],
            'fallback_price_policy' => ['sometimes', 'in:MATCH_PRIMARY_WINNER_VALUE,SECOND_RANK_OWN_VALUE,ADMIN_APPROVED_WITHIN_POLICY'],
            'winner_confirmation_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'winner_default_policy' => ['sometimes', 'in:NO_FORFEITURE,ACTUAL_LOSS_ONLY,FULL_EMD_FORFEITURE,ADMIN_APPROVED'],
            'fallback_exhaustion_policy' => ['sometimes', 'in:NO_FURTHER_FALLBACK,NEXT_RANK_ALLOWED,REAUCTION_REQUIRED,ADMIN_MANUAL_DECISION'],
        ]);
        $auction->update(['config_draft' => array_merge($auction->config_draft ?? [], $data)]);
        return new AuctionResource($auction->fresh());
    }

    public function publish(Request $request, string $code): AuctionResource
    {
        $data = $request->validate([
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['string', 'max:60'],
        ]);

        $auction = Auction::where('code', $code)->firstOrFail();

        abort_unless($auction->status === 'approved', 422, 'Only an approved auction can be published.');

        $config = array_merge([
            'emd_percentage' => \App\Services\GeneralSettings::int('emd_percentage', 10),
            'minimum_participants' => \App\Services\GeneralSettings::int('minimum_participants', 3),
            'initial_slot_minutes' => \App\Services\GeneralSettings::int('initial_slot_minutes', 30),
            'continuation_slot_minutes' => \App\Services\GeneralSettings::int('continuation_slot_minutes', 2),
            'bid_cutoff_ms' => \App\Services\GeneralSettings::int('bid_cutoff_ms', 500),
            'maximum_auction_duration_minutes' => \App\Services\GeneralSettings::int('maximum_auction_duration_minutes', 120),
            'auction_edit_lock_hours' => \App\Services\GeneralSettings::int('auction_edit_lock_hours', 3),
            'bid_increment' => (float) $auction->bid_increment,
            'direction' => $auction->direction,
            'rfq_required' => \App\Services\GeneralSettings::int('rfq_required', 0) === 1,
            'rfq_mode' => \App\Services\GeneralSettings::string('rfq_mode', 'DOCUMENT'),
            'rfq_benchmark_strategy' => \App\Services\GeneralSettings::string('rfq_benchmark_strategy', 'HIGHEST_VALID'),
            'emd_required' => \App\Services\GeneralSettings::int('emd_required', 1) === 1,
            'emd_type' => \App\Services\GeneralSettings::string('emd_type', 'PERCENTAGE'),
            'emd_fixed_amount' => (float) \App\Services\GeneralSettings::int('emd_fixed_amount', 0),
            'emd_payment_deadline_hours' => \App\Services\GeneralSettings::int('emd_payment_deadline_hours', 24),
            'fallback_allowed' => \App\Services\GeneralSettings::int('fallback_allowed', 1) === 1,
            'fallback_price_policy' => \App\Services\GeneralSettings::string('fallback_price_policy', 'SECOND_RANK_OWN_VALUE'),
            'winner_confirmation_hours' => \App\Services\GeneralSettings::int('winner_confirmation_hours', 48),
            'winner_default_policy' => \App\Services\GeneralSettings::string('winner_default_policy', 'NO_FORFEITURE'),
            'fallback_exhaustion_policy' => \App\Services\GeneralSettings::string('fallback_exhaustion_policy', 'ADMIN_MANUAL_DECISION'),
        ], $auction->config_draft ?? []);
        $snapshot = AuctionConfigSnapshot::create([
            'auction_id' => $auction->id,
            'version' => ((int) $auction->configSnapshots()->max('version')) + 1,
            'config' => $config,
            'frozen_by' => $request->user()?->id,
            'frozen_at' => now(),
        ]);

        $version = AuctionTermsVersion::create([
            'auction_id' => $auction->id,
            'version' => ((int) $auction->termsVersions()->max('version')) + 1,
            'published_by' => $request->user()?->id,
            'terms_text' => $auction->terms,
            'rules' => [
                'emd_amount' => (float) $auction->emd_amount,
                'direction' => $auction->direction,
                'bid_increment' => (float) $auction->bid_increment,
                'schedule_start' => $auction->schedule_start?->toIso8601String(),
                'schedule_end' => $auction->schedule_end?->toIso8601String(),
                'payment_terms' => $auction->payment_terms,
                'lifting_period' => $auction->lifting_period,
                'auction_edit_lock_hours' => $config['auction_edit_lock_hours'],
                'config_snapshot_id' => $snapshot->id,
            ],
            'published_at' => now(),
        ]);

        $auction->update([
            'status' => 'published',
            'published_at' => now(),
            'publish_channels' => $data['channels'] ?? ['Web Portal', 'Mobile App', 'Email'],
            'current_terms_version_id' => $version->id,
            'config_snapshot_id' => $snapshot->id,
        ]);

        $this->notifications->auctionPublished($auction);
        broadcast(new AuctionStateChanged($auction, 'published'));

        return new AuctionResource($auction);
    }

    /** Flip a published auction into live bidding. */
    public function golive(string $code): AuctionResource
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        // START is safe to retry after the first committed start. Return the
        // authoritative state instead of treating a double-click as an error.
        if ($auction->status === 'live' && $auction->actual_started_at) {
            return new AuctionResource($auction->fresh(['slots']));
        }

        abort_unless(
            in_array($auction->status, ['published', 'approved'], true),
            422,
            'Only a published auction can go live.',
        );

        $readiness = app(AuctionReadinessService::class)->check($auction);
        abort_unless($readiness['ready'], 422, 'Auction is not ready: '.implode(', ', $readiness['reasons']));

        $auction = \DB::transaction(function () use ($auction) {
            $locked = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();
            if ($locked->actual_started_at) return $locked;
            $startsAt = now();
            $duration = (int) $locked->frozenConfig('initial_slot_minutes', \App\Services\GeneralSettings::int('initial_slot_minutes', 30));
            $endsAt = $startsAt->copy()->addMinutes($duration);
            $hardEnd = $startsAt->copy()->addMinutes((int) $locked->frozenConfig('maximum_auction_duration_minutes', \App\Services\GeneralSettings::int('maximum_auction_duration_minutes', 120)));
            if ($endsAt->gt($hardEnd)) $endsAt = $hardEnd;
            AuctionSlot::firstOrCreate(['auction_id'=>$locked->id,'sequence'=>1],['type'=>'initial','starts_at'=>$startsAt,'ends_at'=>$endsAt,'cutoff_at'=>$endsAt->copy()->subMilliseconds((int)$locked->frozenConfig('bid_cutoff_ms',500)),'status'=>'active']);
            $locked->update(['status'=>'live','actual_started_at'=>$startsAt,'schedule_end'=>$hardEnd]);
            return $locked->fresh(['slots']);
        });

        broadcast(new AuctionStateChanged($auction, 'live'));

        return new AuctionResource($auction);
    }

    public function readiness(string $code): JsonResponse
    {
        return response()->json(['success'=>true,'data'=>app(AuctionReadinessService::class)->check(Auction::where('code',$code)->firstOrFail())]);
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

    public function closeSlot(Request $request, string $code, int $slot): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'in:TIME_ELAPSED,NO_CONTINUATION,MAX_DURATION,ADMIN_FORCE_CLOSE,AUCTION_CANCELLED']]);
        $auction = Auction::where('code', $code)->firstOrFail();
        $slotModel = \DB::transaction(function () use ($auction, $slot, $data) {
            $slotModel = $auction->slots()->whereKey($slot)->lockForUpdate()->firstOrFail();
            if ($slotModel->status === 'closed') return $slotModel;
            $slotModel->update(['status' => 'closed', 'closed_at' => now(), 'close_reason' => $data['reason']]);
            return $slotModel->fresh();
        });
        broadcast(new AuctionStateChanged($auction->fresh(), 'slot_closed'));
        return response()->json(['slot' => $slotModel, 'auction' => new AuctionResource($auction->fresh(['slots']))]);
    }

    public function createContinuationSlot(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->lockForUpdate()->firstOrFail();
        abort_unless($auction->status === 'live', 422, 'Only a live auction can create a continuation slot.');
        $last = $auction->slots()->latest('sequence')->lockForUpdate()->firstOrFail();
        abort_if($last->status !== 'closed', 422, 'The current slot must be closed first.');
        $active = $auction->slots()->where('status', 'active')->first();
        if ($active) return response()->json(['slot' => $active, 'idempotent' => true], 200);

        $start = now();
        $hardEnd = ($auction->actual_started_at ?? $auction->schedule_start ?? $auction->published_at ?? $auction->created_at)
            ->copy()->addMinutes((int) $auction->frozenConfig('maximum_auction_duration_minutes', \App\Services\GeneralSettings::int('maximum_auction_duration_minutes', 120)));
        $duration = (int) $auction->frozenConfig('continuation_slot_minutes', \App\Services\GeneralSettings::int('continuation_slot_minutes', 2));
        $end = min($start->copy()->addMinutes($duration)->getTimestamp(), $hardEnd->getTimestamp());
        abort_if($end <= $start->getTimestamp(), 422, 'Maximum auction duration has been reached.');
        $end = now()->setTimestamp($end);
        $slot = $auction->slots()->create([
            'sequence' => $last->sequence + 1,
            'type' => 'continuation',
            'starts_at' => $start,
            'ends_at' => $end,
            'cutoff_at' => $end->copy()->subMilliseconds((int) $auction->frozenConfig('bid_cutoff_ms', \App\Services\GeneralSettings::int('bid_cutoff_ms', 500))),
            'status' => 'active',
        ]);
        broadcast(new AuctionStateChanged($auction->fresh(), 'slot_started'));
        return response()->json(['slot' => $slot], 201);
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
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        $isReserveMet = true;
        if (! $auction->isReverse() && $auction->reserve_price && ! $auction->reserve_na && $winning) {
            if ((float) $winning->amount < (float) $auction->reserve_price) {
                $isReserveMet = false;
            }
        }

        $activeSlot = $auction->slots()->where('status', 'active')->lockForUpdate()->first();
        if ($activeSlot) {
            $activeSlot->update(['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'AUCTION_CLOSED']);
        }

        $auction->update([
            'status' => 'closed',
            'closed_at' => now(),
            'final_price' => $winning?->amount ?? $auction->current_highest,
            'winner_vendor_id' => $isReserveMet ? $winning?->vendor_id : null,
            'winner_name' => $isReserveMet ? $winning?->vendor_name : null,
            'review_comment' => ! $isReserveMet ? 'Reserve price not met' : $auction->review_comment,
        ]);

        foreach ($auction->lots as $lot) {
            $lotWinner = $lot->bids()
                ->orderBy('amount', $auction->isReverse() ? 'asc' : 'desc')
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            $lot->update([
                'status' => 'closed',
                'final_price' => $lotWinner?->amount,
                'winner_vendor_id' => $lotWinner?->vendor_id,
            ]);
        }

        if (! $auction->actual_started_at) {
            // Compatibility path for auctions created before authoritative
            // START. New auctions retain the top two EMDs for fallback.
            $this->emd->releaseLosers($auction);
        } else {
            app(AuctionResultService::class)->finalize($auction, 'ADMIN_CLOSE');
        }
        $this->notifications->auctionClosed($auction);
        broadcast(new AuctionStateChanged($auction, 'closed'));

        return new AuctionResource($auction->fresh(['lots']));
    }

    public function result(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $result = $auction->result()->with(['winner', 'secondRank'])->firstOrFail();
        $user = $request->user();
        $staffView = $user?->hasPermission('auction.result.view') && ! $user->hasRole('buyer', 'seller');
        if ($staffView) return response()->json(['success' => true, 'data' => $result]);

        $isSellerOwner = $user?->hasRole('seller') && (int) $auction->submitted_by === (int) $user->id;
        if ($user?->hasRole('seller') && ! $isSellerOwner) abort(403, 'Seller result access is limited to auctions submitted by that seller.');

        $vendorId = $user?->vendor_id;
        if (! $isSellerOwner && (! $vendorId || ! collect($result->ranking_snapshot)->contains(fn ($row) => (int) ($row['vendor_id'] ?? 0) === (int) $vendorId))) {
            abort(403, 'You did not participate in this auction.');
        }
        $confirmation = $vendorId ? WinnerConfirmation::where('result_id', $result->id)->where('participant_id', $vendorId)->latest('id')->first() : null;
        $emd = $vendorId ? $auction->emdTransactions()->where('vendor_id', $vendorId)->latest('id')->first() : null;
        $ownRank = collect($result->ranking_snapshot)->firstWhere('vendor_id', $vendorId);
        return response()->json(['success' => true, 'data' => [
            'id' => $result->id, 'auction_id' => $result->auction_id, 'auction_type' => $result->auction_type,
            'status' => $result->status, 'closed_at' => $result->closed_at, 'final_value' => $result->final_value,
            'config_snapshot_id' => $result->config_snapshot_id, 'terms_version_id' => $result->terms_version_id,
            'participant' => ['vendor_id' => $vendorId, 'rank' => $ownRank['rank'] ?? null, 'amount' => $ownRank['amount'] ?? null, 'confirmation_status' => $confirmation?->confirmation_status, 'response_deadline' => $confirmation?->response_deadline],
            'emd' => $isSellerOwner ? null : ($emd ? ['amount' => $emd->amount, 'status' => $emd->status, 'refunded_amount' => $emd->refunded_amount, 'forfeited_amount' => $emd->forfeited_amount, 'remaining_locked_amount' => max(0, (float) $emd->amount - (float) $emd->refunded_amount - (float) $emd->forfeited_amount), 'reference' => $emd->reference] : null),
            'seller' => $isSellerOwner ? [
                'auction_id' => $auction->id,
                'ranking' => collect($result->ranking_snapshot)->map(fn ($row) => ['rank' => $row['rank'], 'amount' => $row['amount']])->values(),
                'winner_rank' => $auction->isReverse() ? 'L1' : 'H1',
                'result_status' => $result->status,
                'settlement_ready' => ! in_array($result->status, ['provisional_winner', 'FALLBACK_EXHAUSTED_REVIEW_REQUIRED'], true),
            ] : null,
        ]]);
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
            ['auction_id' => $auction->id, 'user_id' => $user->id, 'terms_version_id' => $auction->current_terms_version_id],
            ['ip' => $request->ip(), 'accepted_at' => now()],
        );

        return response()->json([
            'accepted' => true,
            'auction_id' => $auction->code,
            'terms_version_id' => $auction->current_terms_version_id,
            'accepted_at' => now()->toIso8601String(),
        ]);
    }

    public function rfqTemplate(string $code): Response
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $content = "SCRAPIFY AUCTIONS RFQ TEMPLATE\nTEMPLATE_VERSION: 1\nAUCTION_CODE: {$auction->code}\nAUCTION_TITLE: {$auction->title}\nPARTICIPANT_REFERENCE: \nPARTICIPANT_NAME: \nQUANTITY: {$auction->quantity}\nUNIT: {$auction->uom}\nUNIT_PRICE: \nTOTAL_QUOTATION_VALUE: \nTAXES: \nGRAND_TOTAL: \nQUOTATION_REFERENCE: \nQUOTATION_DATE: \nVALIDITY: \nAUTHORIZED_SIGNATORY: \n";
        return response($content, 200, ['Content-Type' => 'text/plain', 'Content-Disposition' => 'attachment; filename="rfq-template-'.$auction->code.'.txt"']);
    }

    public function submitRfq(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail(); $user = $request->user(); $vendor = $user->vendor;
        abort_unless($vendor, 403, 'A vendor profile is required.');
        abort_unless((bool) $auction->frozenConfig('rfq_required', false), 422, 'RFQ is not required for this auction.');
        abort_unless($auction->current_terms_version_id && $auction->termsAcceptances()->where('user_id', $user->id)->where('terms_version_id', $auction->current_terms_version_id)->exists(), 422, 'Accept the current auction terms first.');
        if ($auction->schedule_start && now()->gte($auction->schedule_start)) abort(422, 'RFQ submission window is closed.');
        $data = $request->validate(['file' => ['required','file','mimes:pdf','max:10240'], 'template_version' => ['required','string','max:20']]);
        $file = $data['file']; $hash = hash_file('sha256', $file->getRealPath());
        abort_if(RfqSubmission::where('auction_id',$auction->id)->where('vendor_id',$vendor->id)->where('file_hash',$hash)->exists(), 422, 'This RFQ file was already submitted.');
        $version = ((int) RfqSubmission::where('auction_id',$auction->id)->where('vendor_id',$vendor->id)->max('version')) + 1;
        $path = $file->store('rfq/'.$auction->code);
        $extraction = app(RfqExtractionService::class)->extract($file->getRealPath(), (float) data_get($auction->configSnapshot?->config, 'rfq_confidence_threshold', 0.85));
        $submission = RfqSubmission::create(['auction_id'=>$auction->id,'vendor_id'=>$vendor->id,'submitted_by'=>$user->id,'version'=>$version,'template_version'=>$data['template_version'],'file_path'=>$path,'file_hash'=>$hash,'file_size'=>$file->getSize(),'status'=>$extraction['status'],'extraction_method'=>$extraction['method'],'extracted_amount'=>$extraction['amount'],'normalized_amount'=>$extraction['amount'],'quantity'=>$extraction['quantity'],'unit_amount'=>$extraction['unit_amount'],'calculated_total'=>$extraction['calculated_total'],'confidence'=>$extraction['confidence'],'validation_warnings'=>$extraction['warnings'],'extraction_data'=>$extraction,'submitted_at'=>now()]);
        return response()->json(['success'=>true,'data'=>$submission],201);
    }

    public function reviewRfq(Request $request, string $code, int $submissionId): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $submission = RfqSubmission::where('auction_id', $auction->id)->findOrFail($submissionId);
        $data = $request->validate(['action' => ['required','in:APPROVE,REJECT,CORRECT,RESUBMISSION_REQUIRED'], 'corrected_amount' => ['required_if:action,CORRECT','numeric','min:0'], 'reason' => ['required_if:action,REJECT,CORRECT,RESUBMISSION_REQUIRED','string','max:2000']]);
        $submission->update(['status' => match ($data['action']) { 'APPROVE','CORRECT' => 'approved', 'REJECT' => 'rejected', default => 'resubmission_required' }, 'normalized_amount' => $data['corrected_amount'] ?? $submission->normalized_amount, 'verified_rfq_value' => in_array($data['action'], ['APPROVE','CORRECT'], true) ? ($data['corrected_amount'] ?? $submission->normalized_amount) : null, 'review_reason' => $data['reason'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        return response()->json(['success'=>true,'data'=>$submission->fresh()]);
    }

    public function finalizeRfqBenchmark(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->with('configSnapshot')->firstOrFail();
        abort_if($auction->rfq_benchmark_locked_at, 422, 'RFQ benchmark is already frozen.');
        $values = RfqSubmission::where('auction_id', $auction->id)->where('status', 'approved')->whereNotNull('verified_rfq_value')->pluck('verified_rfq_value')->map(fn ($v) => (float) $v)->values();
        abort_if($values->isEmpty(), 422, 'At least one approved RFQ is required.');
        $strategy = $auction->frozenConfig('rfq_benchmark_strategy', 'HIGHEST_VALID');
        $value = match ($strategy) {
            'LOWEST_VALID' => $values->min(), 'AVERAGE' => $values->avg(), 'MEDIAN' => $values->sort()->values()->get(intdiv($values->count() - 1, 2)), default => $values->max(),
        };
        if ($strategy === 'ADMIN_APPROVED') $value = $request->validate(['value' => ['required','numeric','min:0']])['value'];
        $auction->update(['final_rfq_value' => $value, 'rfq_benchmark_source' => $strategy, 'rfq_benchmark_locked_at' => now()]);
        return response()->json(['success'=>true,'data'=>$auction->fresh()]);
    }

    public function createDiscoveryRound(Request $request, string $code): JsonResponse
    {
        $auction=Auction::where('code',$code)->firstOrFail(); $data=$request->validate(['start_at'=>'nullable|date','end_at'=>'required|date|after:start_at']);
        $round=RfqDiscoveryRound::create(['auction_id'=>$auction->id,'sequence'=>RfqDiscoveryRound::where('auction_id',$auction->id)->max('sequence')+1,'start_at'=>$data['start_at']??now(),'end_at'=>$data['end_at'],'benchmark_strategy'=>$auction->frozenConfig('rfq_benchmark_strategy','HIGHEST_VALID')]); return response()->json(['data'=>$round],201);
    }
    public function startDiscoveryRound(string $code, int $roundId): JsonResponse { $round=RfqDiscoveryRound::whereHas('auction',fn($q)=>$q->where('code',$code))->findOrFail($roundId); abort_unless($round->status==='draft',422,'Round cannot be started.'); $round->update(['status'=>'live','started_by'=>request()->user()->id,'start_at'=>$round->start_at??now()]); return response()->json(['data'=>$round->fresh()]); }
    public function submitDiscovery(Request $request,string $code,int $roundId): JsonResponse { $auction=Auction::where('code',$code)->firstOrFail(); $round=RfqDiscoveryRound::where('auction_id',$auction->id)->findOrFail($roundId); $vendor=$request->user()->vendor; abort_unless($vendor,403,'Vendor profile required.'); abort_unless($round->status==='live' && now()->between($round->start_at,$round->end_at),422,'Discovery round is not open.'); $data=$request->validate(['value'=>'required|numeric|min:0','idempotency_key'=>'required|string|max:100']); $existing=RfqDiscoverySubmission::where(['round_id'=>$round->id,'vendor_id'=>$vendor->id,'idempotency_key'=>$data['idempotency_key']])->first(); if($existing)return response()->json(['data'=>$existing]); $row=RfqDiscoverySubmission::create(['round_id'=>$round->id,'auction_id'=>$auction->id,'vendor_id'=>$vendor->id,'submitted_value'=>$data['value'],'server_received_at'=>now(),'idempotency_key'=>$data['idempotency_key']]); return response()->json(['data'=>$row],201); }
    public function closeDiscoveryRound(Request $request,string $code,int $roundId): JsonResponse { $round=RfqDiscoveryRound::whereHas('auction',fn($q)=>$q->where('code',$code))->with('submissions')->findOrFail($roundId); abort_unless($round->status==='live',422,'Round is not live.'); $round->update(['status'=>'closed','closed_by'=>$request->user()->id,'closed_at'=>now()]); $values=$round->submissions->pluck('submitted_value')->map(fn($v)=>(float)$v); return response()->json(['data'=>$round->fresh(),'summary'=>['lowest'=>$values->min(),'highest'=>$values->max(),'average'=>$values->avg(),'median'=>$values->sort()->values()->get(intdiv(max(0,$values->count()-1),2))]]); }

    /** Lightweight polling fallback for clients not on the websocket. */
    public function liveState(string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->with(['lots', 'slots' => fn ($q) => $q->where('status', 'active')->latest('sequence')])->firstOrFail();
        $slot = $auction->slots->first();
        $serverTime = now();
        $rankedBids = $auction->bids()
            ->when($slot, fn ($q) => $q->where('slot_id', $slot->id))
            ->orderBy('amount', $auction->isReverse() ? 'asc' : 'desc')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $userVendorId = request()->user()?->vendor_id;
        $own = $userVendorId ? $rankedBids->firstWhere('vendor_id', $userVendorId) : null;

        return response()->json([
            'code' => $auction->code,
            'status' => $auction->status,
            'direction' => $auction->direction,
            'current_highest_inr' => $auction->current_highest !== null ? (float) $auction->current_highest : null,
            'bid_increment_inr' => (float) $auction->bid_increment,
            'bidders' => $auction->bidders_count,
            'bid_count' => $rankedBids->count(),
            'last_bid' => $rankedBids->sortByDesc('id')->first() ? [
                'amount_inr' => (float) $rankedBids->sortByDesc('id')->first()->amount,
                'server_received_at' => $rankedBids->sortByDesc('id')->first()->created_at?->toIso8601String(),
            ] : null,
            'ranking' => $rankedBids->take(3)->values()->map(fn ($bid, $index) => [
                'rank' => ($auction->isReverse() ? 'L' : 'H').($index + 1),
                'amount_inr' => (float) $bid->amount,
                'server_received_at' => $bid->created_at?->toIso8601String(),
            ]),
            'own_last_bid' => $own ? ['amount_inr' => (float) $own->amount, 'server_received_at' => $own->created_at?->toIso8601String()] : null,
            'own_rank' => $own ? $rankedBids->search(fn ($bid) => $bid->id === $own->id) + 1 : null,
            'schedule_end' => $auction->schedule_end?->toIso8601String(),
            'actual_started_at' => $auction->actual_started_at?->toIso8601String(),
            'hard_end_at' => $auction->schedule_end?->toIso8601String(),
            'active_slot' => $slot ? [
                'id' => $slot->id,
                'sequence' => $slot->sequence,
                'type' => $slot->type,
                'starts_at' => $slot->starts_at?->toIso8601String(),
                'ends_at' => $slot->ends_at?->toIso8601String(),
                'cutoff_at' => $slot->cutoff_at?->toIso8601String(),
                'status' => $slot->status,
            ] : null,
            'seconds_remaining' => $slot?->ends_at
                ? max(0, $serverTime->diffInSeconds($slot->ends_at, false))
                : null,
            'lots' => $auction->lots->map(fn ($l) => [
                'id' => $l->code,
                'current_bid_inr' => $l->current_bid !== null ? (float) $l->current_bid : null,
                'bidders' => $l->bidders_count,
            ]),
            'server_time' => $serverTime->toIso8601String(),
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
                'uom' => $lot['uom'] ?? $lot['unit'] ?? $auction->uom ?? 'MT',
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

    private function authorizeOwnerOrStaff(Request $request, Auction $auction): void
    {
        $user = $request->user();
        abort_unless($user && ($user->hasPermission('auctions.approve') || $auction->submitted_by === $user->id), 403, 'You may only manage your own auctions.');
    }
}
