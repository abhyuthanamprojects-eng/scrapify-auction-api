<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Award extends Model
{
    protected $guarded = [];

    protected $casts = [
        'award_amount' => 'float',
        'offered_at' => 'datetime',
        'acceptance_deadline' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'winner_vendor_id');
    }

    public function fallbackOffers(): HasMany
    {
        return $this->hasMany(FallbackOffer::class);
    }
}
