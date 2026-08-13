<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'reserve_price' => 'decimal:2',
        'current_bid' => 'decimal:2',
        'final_price' => 'decimal:2',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }
}
