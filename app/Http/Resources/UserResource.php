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
            'database_id' => $this->id,
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
                'code' => $this->vendor->code,
                'company_name' => $this->vendor->company_name,
                'trade_name' => $this->vendor->trade_name,
                'business_type' => $this->vendor->business_type,
                'cin_number' => $this->vendor->cin_number,
                'turnover_band' => $this->vendor->turnover_band,
                'years_in_business' => $this->vendor->years_in_business,
                'annual_capacity' => $this->vendor->annual_capacity,
                'contact_name' => $this->vendor->contact_name,
                'email' => $this->vendor->email,
                'phone' => $this->vendor->phone,
                'location' => $this->vendor->location,
                'address' => $this->vendor->address,
                'address_line1' => $this->vendor->address_line1,
                'city' => $this->vendor->city,
                'state' => $this->vendor->state,
                'pincode' => $this->vendor->pincode,
                'operating_states' => $this->vendor->operating_states ?? [],
                'warehouse_details' => $this->vendor->warehouse_details ?? [],
                'gst_number' => $this->vendor->gst_number,
                'gst_status' => $this->vendor->gst_status ?? 'not_checked',
                'pan_number' => $this->vendor->pan_number,
                'pan_status' => $this->vendor->pan_status ?? 'not_checked',
                'license_number' => $this->vendor->license_number,
                'bank_name' => $this->vendor->bank_name,
                'account_holder_name' => $this->vendor->account_holder_name,
                'bank_status' => $this->vendor->bank_status ?? 'not_checked',
                'status' => $this->vendor->status,
                'rejection_reason' => $this->vendor->rejection_reason,
                'registration_step' => $this->vendor->registration_step,
                'can_bid' => $this->vendor->canBid(),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'approval_status' => $this->whenLoaded('vendor', fn () => $this->vendor?->status),
            'kyb_status' => $this->when($this->relationLoaded('businessVerification'), fn () => $this->businessVerification?->overall_kyb_status ?? ($this->isPublicUser() ? 'NOT_STARTED' : null)),
            'kyc_verified' => $this->relationLoaded('vendor') && $this->vendor?->status === 'approved',
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
