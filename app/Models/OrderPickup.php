<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPickup extends Model
{
    protected $guarded = [];

    protected $casts = ['window_start' => 'datetime', 'window_end' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
