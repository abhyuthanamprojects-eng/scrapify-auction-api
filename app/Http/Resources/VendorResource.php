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
            'company_name' => $this->company_name,
            'location' => $this->location,
            'address' => $this->address,
            'contact_name' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'gst_number' => $this->gst_number,
            'pan_number' => $this->pan_number,
            'license_number' => $this->license_number,
            'material_interest' => $this->whenLoaded('materials', fn () => $this->materials->pluck('name')),
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'suspension_reason' => $this->suspension_reason,
            'registration_step' => $this->registration_step,
            'registration_payment' => [
                'method' => $this->registration_payment_method,
                'reference' => $this->registration_payment_ref,
                'status' => $this->registration_payment_status,
            ],
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
                'approved_on' => $d->approved_on?->toIso8601String(),
                'uploaded_at' => $d->uploaded_at?->toIso8601String(),
            ])),
            'participation' => $this->when(isset($this->participation), fn () => $this->participation),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
