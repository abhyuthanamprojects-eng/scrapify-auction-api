<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorDocument extends Model
{
    protected $guarded = [];

    protected $casts = [
        'required' => 'boolean',
        'approved_on' => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
