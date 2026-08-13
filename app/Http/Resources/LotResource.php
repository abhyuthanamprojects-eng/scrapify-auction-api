<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->code,
            'code' => $this->code,
            'auction_id' => $this->whenLoaded('auction', fn () => $this->auction->code),
            'name' => $this->name,
            'quantity' => $this->quantity,
            'uom' => $this->uom,
            'reserve_price_inr' => $this->reserve_price !== null ? (float) $this->reserve_price : null,
            'current_bid_inr' => $this->current_bid !== null ? (float) $this->current_bid : null,
            'bidders' => $this->bidders_count,
            'status' => $this->status,
            'final_price_inr' => $this->final_price !== null ? (float) $this->final_price : null,
        ];
    }
}
