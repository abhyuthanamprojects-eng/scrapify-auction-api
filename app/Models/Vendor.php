<?php

namespace App\Models;

use App\Support\GeneratesCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendor extends Model
{
    use GeneratesCode;

    protected $guarded = [];

    protected $casts = [
        'terms_accepted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected static string $codePrefix = 'V-';
    protected static int $codePad = 4;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function materials(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VendorDocument::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function canBid(): bool
    {
        return $this->status === 'approved';
    }
}
