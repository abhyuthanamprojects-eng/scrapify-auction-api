<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Single entry point for audit writes. Controllers never call this directly —
 * the RecordsAudit model observer and the AuditableAction event listener do,
 * so sensitive actions cannot be recorded inconsistently or forgotten.
 */
class AuditLogger
{
    public static function write(string $action, ?string $entityType = null, ?string $entityId = null, array $meta = []): ?AuditLog
    {
        $user = Auth::user();

        // Seeding and migrations create records with no actor behind them.
        // Those are not admin actions, so they do not belong in the trail.
        if (! $user && app()->runningInConsole()) {
            return null;
        }

        return AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'role' => $user ? config('roles.labels')[$user->role] ?? $user->role : null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
            'meta' => $meta ?: null,
            'created_at' => now(),
        ]);
    }
}
