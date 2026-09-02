<?php

namespace App\Models;

use App\Support\GeneratesCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorInvitation extends Model
{
    use GeneratesCode;

    protected $guarded = [];

    protected $casts = [
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static string $codePrefix = 'INV-';
    protected static int $codePad = 5;

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
