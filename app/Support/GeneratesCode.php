<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Human-readable primary identifiers (ORG-0001, V-1042, AUC-2026-0031).
 * The React admin panel and the mobile demo both key off these strings,
 * so they are first-class columns rather than derived display values.
 */
trait GeneratesCode
{
    /**
     * The prefix new codes are built from. Models whose prefix varies — the
     * auction code carries the year — override this rather than declaring a
     * static property.
     */
    protected static function codePrefix(): string
    {
        return static::$codePrefix;
    }

    public static function nextCode(?string $prefix = null): string
    {
        $prefix ??= static::codePrefix();
        $pad = static::$codePad ?? 4;

        return DB::transaction(function () use ($prefix, $pad) {
            // Take the highest numeric suffix rather than the newest row —
            // seeded data is not always inserted in code order.
            $n = static::query()
                ->where('code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->pluck('code')
                ->map(fn (string $code) => (int) preg_replace('/\D/', '', substr($code, strlen($prefix))))
                ->max() ?? 0;

            return $prefix.str_pad((string) ($n + 1), $pad, '0', STR_PAD_LEFT);
        });
    }

    protected static function bootGeneratesCode(): void
    {
        static::creating(function ($model) {
            if (empty($model->code)) {
                $model->code = static::nextCode();
            }
        });
    }
}
