<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessVerification extends Model
{
    protected $guarded = [];

    protected $casts = [
        'gst_registered_address' => 'array',
        'business_activities' => 'array',
        'bank_account_encrypted' => 'encrypted',
        'gst_registration_date' => 'date',
        'gstin_verified_at' => 'datetime',
        'pan_verified_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
        'bank_name_match_score' => 'decimal:2',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function vendor(): BelongsTo { return $this->belongsTo(Vendor::class); }
    public function providerRequests(): HasMany { return $this->hasMany(VerificationProviderRequest::class); }
}
