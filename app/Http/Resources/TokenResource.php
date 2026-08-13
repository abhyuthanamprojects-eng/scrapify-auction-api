<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TokenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->code,
            'token' => $this->token,
            'auction_id' => $this->whenLoaded('auction', fn () => $this->auction->code),
            'type' => $this->type,
            'status' => $this->effectiveStatus(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'share_link' => rtrim(config('app.frontend_url', config('app.url')), '/').'/join/'.$this->token,
        ];
    }
}
