<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\Notification;
use App\Models\User;

class NotificationService
{
    public function push(?User $user, string $type, string $title, ?string $body = null, array $data = []): ?Notification
    {
        if (! $user) {
            return null;
        }

        return Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data ?: null,
        ]);
    }

    public function outbid(Bid $previousLeader, Auction $auction, float $newAmount): void
    {
        if (! $previousLeader->user_id) {
            return;
        }

        $this->push(
            User::find($previousLeader->user_id),
            'outbid',
            "You've been outbid",
            sprintf('%s • new bid ₹%s', $auction->title, number_format($newAmount)),
            ['auction_code' => $auction->code, 'amount' => $newAmount],
        );
    }

    public function auctionPublished(Auction $auction): void
    {
        // Everyone who clicked "Interested" while logged in gets told.
        $auction->interestedBidders()->whereNotNull('user_id')->with('user')->each(
            fn ($row) => $this->push(
                $row->user,
                'starting',
                'Auction published',
                "{$auction->title} is now open for bidding.",
                ['auction_code' => $auction->code],
            ),
        );
    }

    public function auctionClosed(Auction $auction): void
    {
        $winnerUserId = $auction->bids()
            ->where('vendor_id', $auction->winner_vendor_id)
            ->value('user_id');

        foreach ($auction->bids()->distinct('user_id')->pluck('user_id')->filter() as $userId) {
            $won = $userId === $winnerUserId;

            $this->push(
                User::find($userId),
                $won ? 'won' : 'lost',
                $won ? 'You won a lot!' : 'Auction closed',
                sprintf('%s • ₹%s', $auction->title, number_format((float) $auction->final_price)),
                ['auction_code' => $auction->code],
            );
        }
    }
}
