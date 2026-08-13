<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only by design. There is no store/update/destroy here, and the table
 * itself rejects UPDATE and DELETE at the database level.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = AuditLog::query()->latest('created_at')->latest('id');

        if ($search = $request->query('search')) {
            $q->where(fn ($w) => $w->where('action', 'like', "%{$search}%")
                ->orWhere('user_name', 'like', "%{$search}%")
                ->orWhere('ip', 'like', "%{$search}%"));
        }

        if ($role = $request->query('role')) {
            $q->where('role', $role);
        }

        if ($from = $request->query('from')) {
            $q->where('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $q->where('created_at', '<=', $to);
        }

        $page = $q->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $l) => [
                'id' => $l->code,
                'at' => $l->created_at?->toIso8601String(),
                'user' => $l->user_name,
                'role' => $l->role,
                'action' => $l->action,
                'entity_type' => $l->entity_type,
                'entity_id' => $l->entity_id,
                'ip' => $l->ip,
                'meta' => $l->meta,
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
