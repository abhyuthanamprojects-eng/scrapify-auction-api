<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InspectionBooking extends Model
{
    protected $guarded = [];

    protected $casts = [
        'slot_date' => 'date',
        'number_of_visitors' => 'integer',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gatePass(): HasOne
    {
        return $this->hasOne(GatePass::class, 'inspection_booking_id');
    }
}
