<?php

namespace App\Events;

use App\Models\Auction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Status changes, extensions and manual close — broadcast on the same
 * `auction.{code}` channel as BidPlaced so a single subscription covers
 * everything a live bidding screen needs.
 */
class AuctionStateChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Auction $auction, public string $reason = 'status')
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('auction.'.$this->auction->code)];
    }

    public function broadcastAs(): string
    {
        return 'auction.state';
    }

    public function broadcastWith(): array
    {
        return [
            'reason' => $this->reason,
            'auction' => [
                'code' => $this->auction->code,
                'status' => $this->auction->status,
                'current_highest' => (float) $this->auction->current_highest,
                'bidders_count' => $this->auction->bidders_count,
                'schedule_start' => $this->auction->schedule_start?->toIso8601String(),
                'schedule_end' => $this->auction->schedule_end?->toIso8601String(),
                'final_price' => $this->auction->final_price ? (float) $this->auction->final_price : null,
                'winner_name' => $this->auction->winner_name,
            ],
        ];
    }
}
