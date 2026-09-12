<?php

namespace App\Services;

use App\Events\BidPlaced;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Lot;
use App\Models\ProxyBid;
use App\Models\User;
use App\Models\Vendor;
use App\Models\AuctionSlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BiddingService
{
    public function __construct(private EmdService $emd, private NotificationService $notifications)
    {
    }

    /**
     * Place a bid.
     *
     * The whole read-validate-write is inside one transaction with a row lock
     * on the auction (and the lot, for lot-wise auctions), so two bidders
     * hitting the same increment cannot both win the race. On MySQL this is a
     * SELECT ... FOR UPDATE; SQLite serialises writes, which covers local dev.
     */
    public function place(Auction $auction, Vendor $vendor, ?User $user, float $amount, ?int $lotId = null, ?string $ip = null, bool $isProxy = false, ?string $idempotencyKey = null): Bid
    {
        return DB::transaction(function () use ($auction, $vendor, $user, $amount, $lotId, $ip, $isProxy, $idempotencyKey) {
            /** @var Auction $auction */
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey) {
                $existing = Bid::where('auction_id', $auction->id)
                    ->where('user_id', $user?->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $slot = $auction->slots()->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now())->lockForUpdate()->first();
            // Backward-compatible recovery for auctions created before slot
            // persistence was introduced. The server still owns the timing.
            if (! $slot && $auction->status === 'live') {
                $endsAt = $auction->schedule_end?->copy() ?? now()->addMinutes(\App\Services\GeneralSettings::int('maximum_auction_duration_minutes', 120));
                $slot = AuctionSlot::firstOrCreate(
                    ['auction_id' => $auction->id, 'sequence' => 1],
                    ['type' => 'initial', 'starts_at' => $auction->schedule_start ?? now(), 'ends_at' => $endsAt, 'cutoff_at' => $endsAt->copy()->subMilliseconds(\App\Services\GeneralSettings::int('bid_cutoff_ms', 500)), 'status' => 'active'],
                );
            }
            abort_unless($slot, 422, 'There is no active auction slot.');
            abort_if(now()->greaterThanOrEqualTo($slot->cutoff_at), 422, 'Bidding is closed for this slot.');

            $lot = null;
            if ($auction->isLotWise()) {
                if (! $lotId) {
                    throw ValidationException::withMessages([
                        'lot_id' => 'This is a lot-wise auction — a lot must be specified.',
                    ]);
                }
                $lot = Lot::where('auction_id', $auction->id)
                    ->whereKey($lotId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $this->assertBiddable($auction, $vendor, $user);

            $hasBids = $lot
                ? Bid::where('lot_id', $lot->id)->exists()
                : Bid::where('auction_id', $auction->id)->whereNull('lot_id')->exists();

            $current = $this->currentPrice($auction, $lot);
            $increment = (float) $auction->bid_increment ?: 0.0;

            if ($auction->isReverse()) {
                // Reverse tender: first quote must be <= starting ceiling; subsequent bids must undercut L1.
                $startCeiling = (float) ($lot?->reserve_price ?? $auction->starting_price ?? $auction->reserve_price ?? 0);
                if (! $hasBids) {
                    if ($startCeiling > 0 && $amount > $startCeiling) {
                        throw ValidationException::withMessages([
                            'amount' => sprintf('First quote must be at most the starting ceiling of %s.', $startCeiling),
                        ]);
                    }
                } else {
                    $ceiling = $current - $increment;
                    if ($amount > $ceiling) {
                        throw ValidationException::withMessages([
                            'amount' => sprintf('Bid must be at most %s (current L1 %s less decrement %s).', $ceiling, $current, $increment),
                        ]);
                    }
                }

                if ($auction->reserve_price && ! $auction->reserve_na && $amount < (float) $auction->reserve_price) {
                    throw ValidationException::withMessages([
                        'amount' => 'Bid is below the configured reserve floor.',
                    ]);
                }
            } else {
                // Forward Auction: first bid must be >= starting price; subsequent bids must exceed H1 + increment.
                $startFloor = (float) ($lot?->reserve_price ?? $auction->starting_price ?? 0);
                if (! $hasBids) {
                    if ($startFloor > 0 && $amount < $startFloor) {
                        throw ValidationException::withMessages([
                            'amount' => sprintf('First bid must be at least the starting price of %s.', $startFloor),
                        ]);
                    }
                } else {
                    $floor = $current + $increment;
                    if ($amount < $floor) {
                        throw ValidationException::withMessages([
                            'amount' => sprintf('Bid must be at least %s (current highest %s plus increment %s).', $floor, $current, $increment),
                        ]);
                    }
                }
            }

            // EMD must be held before a bid counts. Idempotent — locks once.
            $this->emd->ensureLocked($auction, $vendor, $lot?->id);

            $previousLeader = $this->currentLeader($auction, $lot);

            $bid = Bid::create([
                'auction_id' => $auction->id,
                'lot_id' => $lot?->id,
                'vendor_id' => $vendor->id,
                'user_id' => $user?->id,
                'vendor_name' => $vendor->company_name,
                'amount' => $amount,
                'is_proxy' => $isProxy,
                'ip' => $ip,
                'slot_id' => $slot->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->refreshTotals($auction, $lot);

            AuditLogger::write('BID_ACCEPTED', 'bid', (string) $bid->id, [
                'auction_id' => $auction->id, 'slot_id' => $slot->id,
                'amount' => $amount, 'is_proxy' => $isProxy,
                'idempotency_key' => $idempotencyKey,
            ], $user);

            if ($previousLeader && $previousLeader->vendor_id !== $vendor->id) {
                $this->notifications->outbid($previousLeader, $auction, $amount);
            }

            broadcast(new BidPlaced($bid->fresh(['auction', 'lot'])))->toOthers();

            // A losing proxy bid may now want to respond.
            $this->runProxies($auction, $lot, $bid);

            return $bid;
        });
    }

    /**
     * Register or update an auto-bid ceiling (forward) / floor (reverse).
     */
    public function setProxy(Auction $auction, Vendor $vendor, ?User $user, float $max, ?int $lotId = null): ProxyBid
    {
        $this->assertBiddable($auction, $vendor, $user);

        return ProxyBid::updateOrCreate(
            ['auction_id' => $auction->id, 'lot_id' => $lotId, 'vendor_id' => $vendor->id],
            ['user_id' => $user?->id, 'max_amount' => $max, 'is_active' => true],
        );
    }

    public function cancelProxy(Auction $auction, Vendor $vendor, ?int $lotId = null): void
    {
        ProxyBid::where('auction_id', $auction->id)
            ->where('lot_id', $lotId)
            ->where('vendor_id', $vendor->id)
            ->update(['is_active' => false]);
    }

    /**
     * After a manual bid, let one standing proxy respond with the minimum
     * counter-bid it can afford. Single-step by design: the next proxy reacts
     * to that bid in turn, which keeps the ledger readable and avoids a loop
     * inside the transaction.
     */
    private function runProxies(Auction $auction, ?Lot $lot, Bid $triggering): void
    {
        $increment = (float) $auction->bid_increment ?: 0.0;
        $current = $this->currentPrice($auction, $lot);

        $candidates = ProxyBid::where('auction_id', $auction->id)
            ->where('lot_id', $lot?->id)
            ->where('is_active', true)
            ->where('vendor_id', '!=', $triggering->vendor_id)
            ->get();

        foreach ($candidates as $proxy) {
            $target = $auction->isReverse() ? $current - $increment : $current + $increment;
            $canAfford = $auction->isReverse()
                ? $target >= (float) $proxy->max_amount
                : $target <= (float) $proxy->max_amount;

            if (! $canAfford) {
                continue;
            }

            $vendor = Vendor::find($proxy->vendor_id);
            if (! $vendor || ! $vendor->canBid()) {
                continue;
            }

            $this->emd->ensureLocked($auction, $vendor, $lot?->id);

            $bid = Bid::create([
                'auction_id' => $auction->id,
                'lot_id' => $lot?->id,
                'vendor_id' => $vendor->id,
                'user_id' => $proxy->user_id,
                'vendor_name' => $vendor->company_name,
                'amount' => $target,
                'is_proxy' => true,
            ]);

            $this->refreshTotals($auction, $lot);
            $this->notifications->outbid($triggering, $auction, $target);
            broadcast(new BidPlaced($bid->fresh(['auction', 'lot'])));

            return; // one counter-bid per manual bid
        }
    }

    public function currentPrice(Auction $auction, ?Lot $lot): float
    {
        if ($lot) {
            return (float) ($lot->current_bid ?? $lot->reserve_price ?? $auction->starting_price ?? 0);
        }

        return (float) ($auction->current_highest ?? $auction->starting_price ?? $auction->reserve_price ?? 0);
    }

    private function currentLeader(Auction $auction, ?Lot $lot): ?Bid
    {
        $q = Bid::where('auction_id', $auction->id);
        $lot ? $q->where('lot_id', $lot->id) : $q->whereNull('lot_id');

        return $q->orderBy('amount', $auction->isReverse() ? 'asc' : 'desc')
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    private function refreshTotals(Auction $auction, ?Lot $lot): void
    {
        $direction = $auction->isReverse() ? 'asc' : 'desc';

        if ($lot) {
            $best = Bid::where('lot_id', $lot->id)->orderBy('amount', $direction)->value('amount');
            $lot->update([
                'current_bid' => $best,
                'bidders_count' => Bid::where('lot_id', $lot->id)->distinct('vendor_id')->count('vendor_id'),
            ]);
        }

        $best = Bid::where('auction_id', $auction->id)->orderBy('amount', $direction)->value('amount');
        $auction->update([
            'current_highest' => $best,
            'bidders_count' => Bid::where('auction_id', $auction->id)->distinct('vendor_id')->count('vendor_id'),
        ]);
    }

    private function assertBiddable(Auction $auction, Vendor $vendor, ?User $user = null): void
    {
        if ($auction->status !== 'live') {
            throw ValidationException::withMessages([
                'auction' => "Auction {$auction->code} is not live (status: {$auction->status}).",
            ]);
        }

        $hardDuration = (int) $auction->frozenConfig(
            'maximum_auction_duration_minutes',
            \App\Services\GeneralSettings::int('maximum_auction_duration_minutes', 120),
        );
        // A started auction has a server-owned hard stop. Legacy live rows may
        // predate actual_started_at, so keep their persisted schedule_end rather
        // than retroactively expiring them from an inferred historical timestamp.
        $hardEnd = $auction->actual_started_at
            ? $auction->actual_started_at->copy()->addMinutes($hardDuration)
            : ($auction->schedule_end ?? $auction->schedule_start?->copy()->addMinutes($hardDuration));
        if ($hardEnd && $auction->schedule_end && $hardEnd->gt($auction->schedule_end)) {
            $hardEnd = $auction->schedule_end->copy();
        }
        if ($hardEnd && $hardEnd->isPast()) {
            throw ValidationException::withMessages([
                'auction' => 'Bidding has closed for this auction.',
            ]);
        }

        if ($auction->actual_started_at && $auction->config_snapshot_id && $auction->current_terms_version_id) {
            $eligibility = app(AuctionEligibilityService::class)->evaluate($auction, $vendor);
            if (! $eligibility['eligible']) {
                throw ValidationException::withMessages(['participant' => 'Participant is not eligible: '.implode(', ', $eligibility['reasons'])]);
            }
        }

        if (! $vendor->canBid()) {
            throw ValidationException::withMessages([
                'vendor' => "Bidding is locked until your registration is approved (status: {$vendor->status}).",
            ]);
        }

        // Self-bidding prevention: auction creator/owner cannot bid on their own auction
        if ($user && $auction->submitted_by && (int) $auction->submitted_by === (int) $user->id) {
            throw ValidationException::withMessages([
                'vendor' => 'You cannot place bids on your own auction.',
            ]);
        }

        if ($auction->organization_id && $vendor->organization_id && (int) $auction->organization_id === (int) $vendor->organization_id) {
            throw ValidationException::withMessages([
                'vendor' => 'Members of the hosting organization cannot bid on this auction.',
            ]);
        }

        // User-side role rules: sellers create/own auctions; buyers participate
        // in both forward and reverse auctions. The auction direction changes
        // price ranking, not who owns the participant relationship.
        $role = $vendor->user?->role ?? $user?->role;
        if ($role !== 'buyer') {
            throw ValidationException::withMessages([
                'vendor' => 'Only buyer accounts can participate in auctions.',
            ]);
        }
    }
}
