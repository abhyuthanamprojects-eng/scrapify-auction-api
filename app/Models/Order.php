<?php

namespace App\Models;

use App\Support\GeneratesCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Order extends Model
{
    use GeneratesCode;

    protected $guarded = [];

    protected $casts = [
        'winning_amount' => 'decimal:2',
        'emd_applied' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'tcs_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'balance_due' => 'decimal:2',
        'payment_due_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    protected static string $codePrefix = 'BP-';
    protected static int $codePad = 6;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pickup(): HasOne
    {
        return $this->hasOne(OrderPickup::class)->latestOfMany();
    }

    public function weighbridge(): HasOne
    {
        return $this->hasOne(WeighbridgeRecord::class)->latestOfMany();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OrderDocument::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }
}
