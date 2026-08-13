<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Warehouse extends Model
{
    protected $guarded = [];

    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class);
    }
}
