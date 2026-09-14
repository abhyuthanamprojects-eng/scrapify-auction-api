<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuctionTemplateUpload extends Model
{
    protected $guarded = [];

    protected $casts = [
        'validation_errors' => 'array',
        'parsed_summary' => 'array',
        'confirmed_at' => 'datetime',
        'total_reference_value' => 'decimal:2',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AuctionTemplate::class, 'template_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AuctionItem::class, 'upload_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
