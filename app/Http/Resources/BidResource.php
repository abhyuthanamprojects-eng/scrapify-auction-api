<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BidResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $auction = $this->relationLoaded('auction') ? $this->auction : null;
        $user = $request->user();
        $private = $user?->isAdmin()
            || ($user?->hasRole('seller') && $auction && (int) $auction->submitted_by === (int) $user->id);

        return [
            'id' => $this->id,
            'slot_id' => $this->slot_id,
            'auction_id' => $this->whenLoaded('auction', fn () => $this->auction->code),
            'sub_lot_id' => $this->whenLoaded('lot', fn () => $this->lot?->code),
            'vendor_id' => $private ? $this->whenLoaded('vendor', fn () => $this->vendor->code, $this->vendor_id) : null,
            'vendor_name' => $private ? $this->vendor_name : 'Participant',
            'amount' => (float) $this->amount,
            'amount_inr' => (float) $this->amount,
            'is_proxy' => $this->is_proxy,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
