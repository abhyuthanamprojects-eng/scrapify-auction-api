<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityVerification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'code_verifier_encrypted' => 'encrypted',
            'dob_encrypted' => 'encrypted',
            'metadata_encrypted' => 'encrypted',
            'initiated_at' => 'datetime',
            'expires_at' => 'datetime',
            'callback_received_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
