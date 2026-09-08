<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuctionTermsVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'rules' => 'array',
        'published_at' => 'datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(AuctionTermsAcceptance::class, 'terms_version_id');
    }
}
