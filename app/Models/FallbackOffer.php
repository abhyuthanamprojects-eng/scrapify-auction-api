<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FallbackOffer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'offer_amount' => 'float',
        'price_delta' => 'float',
        'expires_at' => 'datetime',
    ];

    public function award(): BelongsTo
    {
        return $this->belongsTo(Award::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
