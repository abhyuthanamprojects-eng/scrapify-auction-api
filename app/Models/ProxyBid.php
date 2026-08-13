<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProxyBid extends Model
{
    protected $guarded = [];

    protected $casts = ['max_amount' => 'decimal:2', 'is_active' => 'boolean'];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
