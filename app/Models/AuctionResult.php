<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionResult extends Model
{
    protected $guarded = [];
    protected $casts = [
        'ranking_snapshot' => 'array',
        'final_value' => 'decimal:2',
        'actual_started_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function auction(): BelongsTo { return $this->belongsTo(Auction::class); }
    public function winner(): BelongsTo { return $this->belongsTo(Vendor::class, 'winner_vendor_id'); }
    public function secondRank(): BelongsTo { return $this->belongsTo(Vendor::class, 'second_rank_vendor_id'); }
}
