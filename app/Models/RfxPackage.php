<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RfxPackage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'min_passing_score' => 'float',
        'submission_deadline' => 'datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(RfxQuestion::class)->orderBy('sort_order');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(RfxResponse::class);
    }
}
