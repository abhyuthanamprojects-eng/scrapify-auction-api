<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionConfigSnapshot extends Model
{
    protected $guarded = [];
    protected $casts = ['config' => 'array', 'frozen_at' => 'datetime'];

    public function auction(): BelongsTo { return $this->belongsTo(Auction::class); }
}
