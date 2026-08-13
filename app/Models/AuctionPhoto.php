<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionPhoto extends Model
{
    protected $guarded = [];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }
}
