<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeighbridgeRecord extends Model
{
    protected $guarded = [];

    protected $casts = [
        'declared_kg' => 'decimal:2',
        'actual_kg' => 'decimal:2',
        'adjustment_amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
