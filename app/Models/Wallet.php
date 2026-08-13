<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $guarded = [];

    protected $casts = ['balance' => 'decimal:2', 'locked' => 'decimal:2'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest('id');
    }

    /** Balance minus EMD currently held. */
    public function available(): float
    {
        return (float) $this->balance - (float) $this->locked;
    }
}
