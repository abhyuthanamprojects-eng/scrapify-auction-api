<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionDocument extends Model
{
    protected $guarded = [];

    protected $casts = [
        'required' => 'boolean',
        'reviewed_at' => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    public const DOC_TYPES = ['catalog', 'tnc', 'photographs'];

    public const STATUSES = [
        'pending_review',
        'verified',
        'changes_required',
        'rejected',
        'not_applicable',
    ];

    public const FORWARD_REQUIRED = [
        'catalog' => true,
        'photographs' => true,
        'tnc' => false,
    ];

    public const REVERSE_REQUIRED = [
        'catalog' => false,
        'photographs' => false,
        'tnc' => false,
    ];

    public static function requiredDocsForDirection(string $direction): array
    {
        return $direction === 'forward' ? self::FORWARD_REQUIRED : self::REVERSE_REQUIRED;
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
