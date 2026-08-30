<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RiskFlag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RiskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = RiskFlag::with('resolvedBy');

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        if ($severity = $request->query('severity')) {
            $q->where('severity', $severity);
        }

        $flags = $q->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $flags->items(),
            'meta' => [
                'current_page' => $flags->currentPage(),
                'last_page' => $flags->lastPage(),
                'total' => $flags->total(),
            ],
        ]);
    }

    public function resolve(Request $request, string $code): JsonResponse
    {
        $flag = RiskFlag::where('code', $code)->firstOrFail();
        $user = $request->user();

        $validated = $request->validate([
            'status' => 'required|in:resolved,false_positive,restricted',
            'notes' => 'nullable|string',
        ]);

        $flag->update([
            'status' => $validated['status'],
            'resolved_by_user_id' => $user->id,
            'resolved_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Risk incident updated to: '.$validated['status'],
            'data' => $flag,
        ]);
    }
}
