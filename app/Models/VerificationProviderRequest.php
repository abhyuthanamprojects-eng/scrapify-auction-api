<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationProviderRequest extends Model
{
    protected $guarded = [];
    protected $casts = ['normalized_response' => 'encrypted', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    public function verification(): BelongsTo { return $this->belongsTo(BusinessVerification::class, 'business_verification_id'); }
}
