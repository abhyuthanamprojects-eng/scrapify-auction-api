<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RfxResponse extends Model
{
    protected $guarded = [];

    protected $casts = [
        'answers' => 'array',
        'score' => 'float',
        'submitted_at' => 'datetime',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(RfxPackage::class, 'rfx_package_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function evaluations(): HasMany
    {
        return $this->hasMany(RfxEvaluation::class);
    }
}
