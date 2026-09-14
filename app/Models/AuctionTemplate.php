<?php

namespace App\Models;

use App\Support\GeneratesCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuctionTemplate extends Model
{
    use GeneratesCode;

    protected $guarded = [];

    protected static string $codePrefix = 'TPL-';
    protected static int $codePad = 4;

    protected $casts = [
        'schema_definition' => 'array',
        'template_required' => 'boolean',
        'allow_manual_items' => 'boolean',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(AuctionTemplateUpload::class, 'template_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isUsedByAuction(): bool
    {
        return Auction::where('template_id', $this->id)->exists();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForCategory($query, int $categoryId, ?int $subcategoryId = null)
    {
        $query->where('category_id', $categoryId);
        if ($subcategoryId) {
            $query->where('subcategory_id', $subcategoryId);
        }

        return $query;
    }

    public function scopeForDirection($query, string $direction)
    {
        return $query->whereIn('direction', [$direction, 'both']);
    }
}
