<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AddendumAcknowledgement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'acknowledged_at' => 'datetime',
    ];

    public function addendum(): BelongsTo
    {
        return $this->belongsTo(AuctionAddendum::class, 'auction_addendum_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
