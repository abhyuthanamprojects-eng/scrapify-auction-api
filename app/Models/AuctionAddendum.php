<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuctionAddendum extends Model
{
    protected $table = 'auction_addenda';

    protected $guarded = [];

    protected $casts = [
        'version' => 'integer',
        'published_at' => 'datetime',
    ];

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(AddendumAcknowledgement::class, 'auction_addendum_id');
    }
}
