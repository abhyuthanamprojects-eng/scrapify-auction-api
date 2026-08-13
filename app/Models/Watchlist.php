<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Watchlist extends Model
{
    protected $guarded = [];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }
}
