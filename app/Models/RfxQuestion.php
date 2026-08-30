<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfxQuestion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'options' => 'array',
        'weight' => 'float',
        'is_required' => 'boolean',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(RfxPackage::class, 'rfx_package_id');
    }
}
