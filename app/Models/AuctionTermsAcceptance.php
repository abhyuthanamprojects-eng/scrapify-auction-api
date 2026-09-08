<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionTermsAcceptance extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['accepted_at' => 'datetime'];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function termsVersion(): BelongsTo
    {
        return $this->belongsTo(AuctionTermsVersion::class, 'terms_version_id');
    }
}
