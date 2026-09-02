<?php

namespace App\Models;

use App\Support\GeneratesCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use GeneratesCode;

    protected $guarded = [];

    protected $casts = [
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    protected static string $codePrefix = 'ORG-';
    protected static int $codePad = 4;

    public function units(): HasMany
    {
        return $this->hasMany(OrganizationUnit::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(OrganizationDocument::class);
    }

    public function plants(): HasMany
    {
        return $this->hasMany(Plant::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function auctions(): HasMany
    {
        return $this->hasMany(Auction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
