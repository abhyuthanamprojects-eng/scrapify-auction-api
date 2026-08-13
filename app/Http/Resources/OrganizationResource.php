<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->code,
            'code' => $this->code,
            'company_name' => $this->company_name,
            'location' => $this->location,
            'total_units' => $this->total_units,
            'status' => $this->status,
            'rejection_reason' => $this->rejection_reason,
            'bank' => $this->bank_account_number ? [
                'account_number' => $this->bank_account_number,
                'ifsc' => $this->bank_ifsc,
                'bank_name' => $this->bank_name,
            ] : null,
            'units' => $this->whenLoaded('units', fn () => $this->units->map(fn ($u) => [
                'id' => $u->code,
                'name' => $u->name,
                'gst' => $u->gst,
                'location' => $u->location,
                'bank' => [
                    'account_number' => $u->bank_account_number,
                    'ifsc' => $u->bank_ifsc,
                    'bank_name' => $u->bank_name,
                ],
            ])),
            'documents' => $this->whenLoaded('documents', fn () => $this->documents->map(fn ($d) => [
                'id' => $d->code ?? (string) $d->id,
                'type' => $d->type,
                'file_name' => $d->file_name,
                'uploaded_at' => $d->uploaded_at?->toIso8601String(),
            ])),
            'plants' => $this->whenLoaded('plants', fn () => $this->plants->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'warehouses' => $p->relationLoaded('warehouses')
                    ? $p->warehouses->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])
                    : [],
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
