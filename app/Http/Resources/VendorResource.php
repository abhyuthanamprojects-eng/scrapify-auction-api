<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VendorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->code,
            'code' => $this->code,
            'user_id' => $this->user_id,
            'user_role' => $this->user?->role ?? 'buyer',

            // Company & Business Info
            'company_name' => $this->company_name,
            'trade_name' => $this->trade_name,
            'business_type' => $this->business_type,
            'cin_number' => $this->cin_number,
            'turnover_band' => $this->turnover_band,
            'years_in_business' => $this->years_in_business,
            'annual_capacity' => $this->annual_capacity,

            // Contact & Addresses
            'contact_name' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'location' => $this->location,
            'address' => $this->address,
            'address_line1' => $this->address_line1,
            'city' => $this->city,
            'state' => $this->state,
            'pincode' => $this->pincode,
            'operating_states' => $this->operating_states ?? [],

            // Tax & Identifiers
            'gst_number' => $this->gst_number,
            'gst_status' => $this->gst_status ?? 'not_checked',
            'pan_number' => $this->pan_number,
            'pan_status' => $this->pan_status ?? 'not_checked',
            'license_number' => $this->license_number,

            // Banking Details
            'bank_name' => $this->bank_name,
            'account_number' => $this->account_number,
            'ifsc_code' => $this->ifsc_code,
            'account_holder_name' => $this->account_holder_name,
            'branch_name' => $this->branch_name,
            'account_type' => $this->account_type,
            'bank_status' => $this->bank_status ?? 'not_checked',

            // Signatory
            'signatory_name' => $this->signatory_name,
            'signatory_designation' => $this->signatory_designation,
            'signatory_email' => $this->signatory_email,
            'signatory_phone' => $this->signatory_phone,

            // Category / Materials
            'material_interest' => $this->whenLoaded('materials', fn () => $this->materials->pluck('name')),

            // Status & Lifecycle
            'status' => $this->status ?: 'pending',
            'can_bid' => $this->canBid(),
            'rejection_reason' => $this->rejection_reason,
            'rejection_items' => $this->rejection_items ?? [],
            'suspension_reason' => $this->suspension_reason,
            'registration_step' => $this->registration_step ?? 1,
            'terms_accepted_at' => $this->terms_accepted_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by,

            'registration_payment' => [
                'method' => $this->registration_payment_method,
                'reference' => $this->registration_payment_ref,
                'status' => $this->registration_payment_status,
            ],

            // Documents
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($d) => [
                'id' => $d->id,
                'key' => $d->doc_key,
                'kind' => $d->kind,
                'name' => $d->name ?? $d->kind,
                'file_name' => $d->file_name,
                'size_kb' => $d->size_kb,
                'required' => $d->required,
                'status' => $d->status,
                'reason' => $d->reason,
                'ocr_status' => $d->ocr_status,
                'ocr_confidence' => $d->ocr_confidence,
                'ocr_extracted_data' => $d->ocr_extracted_data,
                'approved_on' => $d->approved_on?->toIso8601String(),
                'uploaded_at' => $d->uploaded_at?->toIso8601String(),
            ])),

            'participation' => $this->when(isset($this->participation), fn () => $this->participation),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
