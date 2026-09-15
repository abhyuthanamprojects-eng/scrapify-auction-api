<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermsCondition extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCategory($query, ?int $categoryId, ?int $subcategoryId = null)
    {
        return $query->where(function ($q) use ($categoryId, $subcategoryId) {
            $q->whereNull('category_id');
            $ids = array_filter([$categoryId, $subcategoryId]);
            if ($ids) {
                $q->orWhereIn('category_id', $ids);
            }
        });
    }

    public function scopeForRole($query, string $role)
    {
        return $query->whereIn('applicable_to', [$role, 'all']);
    }
}
