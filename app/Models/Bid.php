<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bid extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'is_proxy' => 'boolean'];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
