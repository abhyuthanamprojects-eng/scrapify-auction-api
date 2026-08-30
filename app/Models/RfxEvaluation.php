<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfxEvaluation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'technical_score' => 'float',
        'commercial_score' => 'float',
        'total_score' => 'float',
        'passed' => 'boolean',
    ];

    public function response(): BelongsTo
    {
        return $this->belongsTo(RfxResponse::class, 'rfx_response_id');
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }
}
