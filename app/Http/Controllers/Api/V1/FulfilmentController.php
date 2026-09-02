<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FulfilmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = Order::query()->with(['auction', 'lot', 'vendor', 'pickup', 'weighbridge', 'documents']);

        if ($status = $request->query('status')) {
            $q->whereIn('status', array_map('trim', explode(',', $status)));
        }

        if ($search = $request->query('search')) {
            $q->where(fn ($w) => $w->where('code', 'like', "%{$search}%")
                ->orWhereHas('auction', fn ($a) => $a->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%"))
                ->orWhereHas('vendor', fn ($v) => $v->where('company_name', 'like', "%{$search}%")));
        }

        if ($from = $request->query('from')) {
            $q->where('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $q->where('created_at', '<=', $to);
        }

        $orders = $q->latest('id')->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'data' => $orders->getCollection()->map(fn (Order $o) => [
                'id' => $o->code,
                'auction_id' => $o->auction?->code,
                'auction_title' => $o->auction?->title,
                'lot_id' => $o->lot?->code,
                'vendor_id' => $o->vendor?->code,
                'vendor_name' => $o->vendor?->company_name,
                'winning_amount_inr' => (float) $o->winning_amount,
                'total_amount_inr' => (float) $o->total_amount,
                'balance_due_inr' => (float) $o->balance_due,
                'status' => $o->status,
                'payment_due_at' => $o->payment_due_at?->toIso8601String(),
                'paid_at' => $o->paid_at?->toIso8601String(),
                'pickup_status' => $o->pickup?->status,
                'pickup_window' => $o->pickup ? [
                    'start' => $o->pickup->window_start?->toIso8601String(),
                    'end' => $o->pickup->window_end?->toIso8601String(),
                ] : null,
                'weighbridge_done' => $o->weighbridge !== null,
                'documents_count' => $o->documents->count(),
                'created_at' => $o->created_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }
}
