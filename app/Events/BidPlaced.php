<?php

namespace App\Events;

use App\Models\Bid;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on every accepted bid. The admin live monitor, the React web
 * bidder and the Flutter app all subscribe to the same public channel:
 *
 *     auction.{code}
 *
 * Run `php artisan reverb:start` locally to serve it.
 */
class BidPlaced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Bid $bid)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('auction.'.$this->bid->auction->code)];
    }

    public function broadcastAs(): string
    {
        return 'bid.placed';
    }

    public function broadcastWith(): array
    {
        $auction = $this->bid->auction;

        return [
            'bid' => [
                'id' => $this->bid->id,
                'auction_code' => $auction->code,
                'lot_code' => $this->bid->lot?->code,
                'vendor_id' => $this->bid->vendor_id,
                'vendor_name' => $this->bid->vendor_name,
                'amount' => (float) $this->bid->amount,
                'is_proxy' => $this->bid->is_proxy,
                'at' => $this->bid->created_at->toIso8601String(),
            ],
            'auction' => [
                'code' => $auction->code,
                'direction' => $auction->direction,
                'current_highest' => (float) $auction->current_highest,
                'bidders_count' => $auction->bidders_count,
                'schedule_end' => $auction->schedule_end?->toIso8601String(),
            ],
        ];
    }
}
