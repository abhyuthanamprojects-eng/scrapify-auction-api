<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'meta' => 'array',
        'paid_at' => 'datetime',
    ];

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
