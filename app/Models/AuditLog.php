<?php

namespace App\Models;

use App\Support\GeneratesCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only. UPDATE and DELETE are blocked by database triggers as well
 * as by the guards below, so a bug in application code cannot rewrite history.
 */
class AuditLog extends Model
{
    use GeneratesCode;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];

    protected static string $codePrefix = 'AL-';
    protected static int $codePad = 4;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \RuntimeException('audit_logs is append-only'));
        static::deleting(fn () => throw new \RuntimeException('audit_logs is append-only'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
