<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:4',
        'reference_value' => 'decimal:2',
        'reserve_value' => 'decimal:2',
        'attributes' => 'array',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class);
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(AuctionTemplateUpload::class, 'upload_id');
    }
}
