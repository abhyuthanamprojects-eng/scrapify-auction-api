<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->user()->appNotifications();

        if ($request->boolean('unread')) {
            $q->whereNull('read_at');
        }

        $page = $q->paginate((int) $request->query('per_page', 30));

        return response()->json([
            'data' => collect($page->items())->map(fn (Notification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'data' => $n->data,
                'read' => $n->read_at !== null,
                'at' => $n->created_at?->toIso8601String(),
            ]),
            'unread_count' => $request->user()->appNotifications()->whereNull('read_at')->count(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = $request->user()->appNotifications()->findOrFail($id);
        $notification->update(['read_at' => now()]);

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $request->user()->appNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => "Marked {$count} notifications as read."]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json([
            'preferences' => $request->user()->id
                ? NotificationPreference::where('user_id', $request->user()->id)->get(['group', 'key', 'enabled'])
                : [],
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.key' => ['required', 'string', 'max:60'],
            'preferences.*.group' => ['sometimes', 'nullable', 'string', 'max:60'],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        foreach ($data['preferences'] as $pref) {
            NotificationPreference::updateOrCreate(
                ['user_id' => $request->user()->id, 'key' => $pref['key']],
                ['group' => $pref['group'] ?? null, 'enabled' => $pref['enabled']],
            );
        }

        return response()->json(['message' => 'Preferences saved.']);
    }
}
