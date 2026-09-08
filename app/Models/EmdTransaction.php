<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmdTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'required_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'verified_amount' => 'decimal:2', 'refunded_amount' => 'decimal:2', 'forfeited_amount' => 'decimal:2',
        'locked_at' => 'datetime',
        'verified_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
