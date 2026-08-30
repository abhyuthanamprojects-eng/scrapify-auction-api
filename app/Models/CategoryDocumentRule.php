<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryDocumentRule extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'validity_days' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
