<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'role_label' => config('roles.labels')[$this->role] ?? $this->role,
            'status' => $this->status,
            'permissions' => config('roles.permissions')[$this->role] ?? [],
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization?->code,
                'company_name' => $this->organization?->company_name,
            ]),
            'vendor' => $this->whenLoaded('vendor', fn () => $this->vendor ? [
                'id' => $this->vendor->code,
                'company_name' => $this->vendor->company_name,
                'status' => $this->vendor->status,
                'can_bid' => $this->vendor->canBid(),
            ] : null),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
