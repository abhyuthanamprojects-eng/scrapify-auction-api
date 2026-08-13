<?php

namespace App\Services;

use App\Events\BidPlaced;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Lot;
use App\Models\ProxyBid;
use App\Models\User;
use App\Models\Vendor;
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
    public function place(Auction $auction, Vendor $vendor, ?User $user, float $amount, ?int $lotId = null, ?string $ip = null, bool $isProxy = false): Bid
    {
        return DB::transaction(function () use ($auction, $vendor, $user, $amount, $lotId, $ip, $isProxy) {
            /** @var Auction $auction */
            $auction = Auction::whereKey($auction->id)->lockForUpdate()->firstOrFail();

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

            $this->assertBiddable($auction, $vendor);

            $current = $this->currentPrice($auction, $lot);
            $increment = (float) $auction->bid_increment ?: 0.0;

            if ($auction->isReverse()) {
                // Reverse tender: each bid must undercut the standing L1.
                $ceiling = $current - $increment;
                if ($amount > $ceiling) {
                    throw ValidationException::withMessages([
                        'amount' => sprintf('Bid must be at most %s (current L1 %s less increment %s).', $ceiling, $current, $increment),
                    ]);
                }
                if ($auction->reserve_price && ! $auction->reserve_na && $amount < (float) $auction->reserve_price) {
                    throw ValidationException::withMessages([
                        'amount' => 'Bid is below the reserve floor.',
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
            ]);

            $this->refreshTotals($auction, $lot);

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
        $this->assertBiddable($auction, $vendor);

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
            ->orderByDesc('id')
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

    private function assertBiddable(Auction $auction, Vendor $vendor): void
    {
        if ($auction->status !== 'live') {
            throw ValidationException::withMessages([
                'auction' => "Auction {$auction->code} is not live (status: {$auction->status}).",
            ]);
        }

        if ($auction->schedule_end && $auction->schedule_end->isPast()) {
            throw ValidationException::withMessages([
                'auction' => 'Bidding has closed for this auction.',
            ]);
        }

        if (! $vendor->canBid()) {
            throw ValidationException::withMessages([
                'vendor' => "Bidding is locked until your registration is approved (status: {$vendor->status}).",
            ]);
        }
    }
}
