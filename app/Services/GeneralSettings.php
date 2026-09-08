<?php

namespace App\Services;

use App\Models\GeneralSetting;

final class GeneralSettings
{
    public static function string(string $key, string $fallback): string
    {
        $value = GeneralSetting::query()->where('key', $key)->value('value');
        return $value === null ? $fallback : (string) $value;
    }

    public static function int(string $key, int $fallback): int
    {
        $value = GeneralSetting::query()->where('key', $key)->value('value');
        return $value === null ? $fallback : max(0, (int) $value);
    }

    public static function bool(string $key, bool $fallback): bool
    {
        $value = GeneralSetting::query()->where('key', $key)->value('value');
        return $value === null ? $fallback : filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
