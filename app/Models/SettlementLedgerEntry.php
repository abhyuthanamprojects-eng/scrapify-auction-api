<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementLedgerEntry extends Model
{
    protected $guarded = [];
    protected $casts = ['amount' => 'decimal:2', 'approved_at' => 'datetime'];

    public function emd(): BelongsTo { return $this->belongsTo(EmdTransaction::class); }

    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
}
