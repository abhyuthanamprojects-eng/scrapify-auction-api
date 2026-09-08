<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuctionSlot extends Model
{
    protected $guarded = [];
    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'cutoff_at' => 'datetime', 'closed_at' => 'datetime'];

    public function auction(): BelongsTo { return $this->belongsTo(Auction::class); }
    public function bids(): HasMany { return $this->hasMany(Bid::class, 'slot_id'); }
}
