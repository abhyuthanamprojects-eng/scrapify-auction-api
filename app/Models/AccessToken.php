<?php

namespace App\Models;

use App\Support\GeneratesCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AccessToken extends Model
{
    use GeneratesCode;

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static string $codePrefix = 'T-';
    protected static int $codePad = 4;

    public static function makeToken(): string
    {
        return 'tkn_'.Str::upper(Str::random(10));
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /** Effective status — expiry is derived, never a stored lie. */
    public function effectiveStatus(): string
    {
        if ($this->status === 'revoked') {
            return 'revoked';
        }

        return $this->expires_at->isPast() ? 'expired' : 'active';
    }

    public function isUsable(): bool
    {
        return $this->effectiveStatus() === 'active';
    }
}
