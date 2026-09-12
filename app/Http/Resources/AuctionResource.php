<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\GeneralSettings;

/**
 * Field names mirror the admin panel's `Auction` type and the mobile demo's
 * `CreatedAuction` type so both frontends can drop their local stores in
 * favour of this payload without a rename pass.
 */
class AuctionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $private = $user && ($user->hasPermission('auctions.approve') || $this->submitted_by === $user->id);
        return [
            'id' => $this->code,
            'code' => $this->code,
            'title' => $this->title,
            'company' => $this->company,
            'plant' => $this->plant,
            'warehouse' => $this->warehouse,
            'warehouse_details' => $this->warehouse_details,
            'location' => $this->location,
            'description' => $this->description,
            'organization_id' => $this->organization?->code,
            'category' => $this->category?->name,
            'category_id' => $this->category_id,
            'lot_type' => $this->lot_type,
            'direction' => $this->direction,
            'material_type' => $this->material_type,
            'quantity' => $this->quantity,
            'uom' => $this->uom,
            'reserve_price_inr' => $this->reserve_price !== null ? (float) $this->reserve_price : null,
            'reserve_na' => $this->reserve_na,
            'starting_price_inr' => $this->starting_price !== null ? (float) $this->starting_price : null,
            'bid_increment_inr' => (float) $this->bid_increment,
            'emd_amount_inr' => (float) $this->emd_amount,
            'current_highest_inr' => $this->current_highest !== null ? (float) $this->current_highest : null,
            'bidders' => $this->bidders_count,
            'status' => $this->status,
            'submitted_by' => $this->submitted_by_name,
            'owner_user_id' => $this->submitted_by,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'schedule_start' => $this->schedule_start?->toIso8601String(),
            'schedule_end' => $this->schedule_end?->toIso8601String(),
            'actual_started_at' => $this->actual_started_at?->toIso8601String(),
            'hard_end_at' => $this->schedule_end?->toIso8601String(),
            'inspection' => $this->inspection,
            'inspection_date' => $this->inspection_date,
            'inspection_time' => $this->inspection_time,
            'inspection_location' => $this->inspection_location,
            'terms' => $this->terms,
            'terms_version' => $this->current_terms_version_id ? [
                'id' => $this->current_terms_version_id,
                'version' => $this->currentTermsVersion?->version,
                'published_at' => $this->currentTermsVersion?->published_at?->toIso8601String(),
                'rules' => $this->currentTermsVersion?->rules,
            ] : null,
            'slots' => $this->whenLoaded('slots', fn () => $this->slots->map(fn ($slot) => [
                'id' => $slot->id,
                'sequence' => $slot->sequence,
                'type' => $slot->type,
                'starts_at' => $slot->starts_at?->toIso8601String(),
                'ends_at' => $slot->ends_at?->toIso8601String(),
                'cutoff_at' => $slot->cutoff_at?->toIso8601String(),
                'status' => $slot->status,
                'close_reason' => $slot->close_reason,
            ])),
            'payment_terms' => $this->payment_terms,
            'lifting_period' => $this->lifting_period,
            'lifting_unit' => $this->lifting_unit,
            'contact' => [
                'name' => $this->contact_name,
                'phone' => $this->contact_phone,
                'email' => $this->contact_email,
            ],
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->pluck('url')),
            'sub_lots' => LotResource::collection($this->whenLoaded('lots')),
            'bids' => BidResource::collection($this->whenLoaded('bids')),
            'extensions' => $this->whenLoaded('extensions', fn () => $this->extensions->map(fn ($e) => [
                'reason' => $e->reason,
                'minutes' => $e->minutes,
                'at' => $e->created_at?->toIso8601String(),
            ])),
            'review_comment' => $this->when($private, $this->review_comment),
            'published_at' => $this->published_at?->toIso8601String(),
            'publish_channels' => $this->publish_channels,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'final_price_inr' => $this->final_price !== null ? (float) $this->final_price : null,
            'winner' => $this->when($private, $this->winner_name),
            'winner_vendor_id' => $this->when($private, $this->winner_vendor_id),
            'is_reserve_met' => $this->status === 'closed'
                ? ($this->review_comment !== 'Reserve price not met' && ($this->winner_vendor_id !== null || $this->reserve_na || $this->reserve_price === null))
                : null,
            'interested_count' => $this->whenCounted('interestedBidders'),
            'allowed_actions' => [
                'can_edit' => $private && in_array($this->status, ['draft', 'sent_back'], true)
                    && (! $this->schedule_start || now()->lt($this->schedule_start->copy()->subHours(GeneralSettings::int('auction_edit_lock_hours', 3)))),
                'edit_lock_hours' => GeneralSettings::int('auction_edit_lock_hours', 3),
                'can_submit' => in_array($this->status, ['draft', 'sent_back'], true),
                'can_bid' => $this->status === 'live',
                'can_join' => in_array($this->status, ['published', 'live'], true),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
