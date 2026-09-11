<?php

namespace App\Services;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\Notification;
use App\Models\User;

class NotificationService
{
    /** Notify every active internal operator without creating a second channel. */
    public function notifyAdmins(string $type, string $title, ?string $body = null, array $data = [], ?string $businessKey = null): void
    {
        User::query()
            ->where('status', 'active')
            ->whereIn('role', config('roles.admin_roles', []))
            ->each(fn (User $admin) => $this->push(
                $admin,
                $type,
                $title,
                $body,
                $data,
                $businessKey ? "{$businessKey}:admin:{$admin->id}" : null,
            ));
    }

    public function push(?User $user, string $type, string $title, ?string $body = null, array $data = [], ?string $businessKey = null): ?Notification
    {
        if (! $user) {
            return null;
        }

        $businessKey ??= sprintf('%s:%s:%s', $type, $user->id, sha1(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $data['deep_link'] ??= $this->deepLink($type, $data);

        return Notification::firstOrCreate(['business_key' => substr($businessKey, 0, 180)], [
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data ?: null,
        ]);
    }

    private function deepLink(string $type, array $data): ?string
    {
        if (isset($data['result_id'])) {
            return '/results/'.rawurlencode((string) $data['result_id']);
        }

        if (isset($data['verification_id'])) {
            return '/business-verification';
        }

        if (isset($data['auction_code'])) {
            return '/auctions/'.rawurlencode((string) $data['auction_code']);
        }

        if (isset($data['vendor_code'])) {
            return '/vendors/'.rawurlencode((string) $data['vendor_code']);
        }

        if (str_contains(strtolower($type), 'refund') || str_contains(strtolower($type), 'emd')) {
            return '/wallet';
        }

        return null;
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
