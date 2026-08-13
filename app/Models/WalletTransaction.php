<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'balance_after' => 'decimal:2'];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
