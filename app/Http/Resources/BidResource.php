<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BidResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'auction_id' => $this->whenLoaded('auction', fn () => $this->auction->code),
            'sub_lot_id' => $this->whenLoaded('lot', fn () => $this->lot?->code),
            'vendor_id' => $this->whenLoaded('vendor', fn () => $this->vendor->code, $this->vendor_id),
            'vendor_name' => $this->vendor_name,
            'amount_inr' => (float) $this->amount,
            'is_proxy' => $this->is_proxy,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
