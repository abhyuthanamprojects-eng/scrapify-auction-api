<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\EmdTransaction;
use App\Models\Order;
use App\Models\OrderPickup;
use App\Models\Payment;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Post-award fulfilment: the mobile Order Detail screen (pay balance, pickup
 * scheduling, weighbridge, handover OTP, documents). The admin panel has no
 * screen for this yet — the endpoints exist so it can grow one.
 */
class OrderController extends Controller
{
    public function __construct(private WalletService $wallets)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $q = Order::query()->with(['auction', 'lot', 'vendor', 'pickup', 'weighbridge', 'documents']);

        if (! $request->user()->hasPermission('orders.manage')) {
            $q->where('vendor_id', $request->user()->vendor_id);
        }

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return response()->json(['data' => $q->latest('id')->get()->map(fn (Order $o) => $this->present($o))]);
    }

    public function show(Request $request, string $code): JsonResponse
    {
        $order = Order::where('code', $code)
            ->with(['auction', 'lot', 'vendor', 'pickup', 'weighbridge', 'documents'])
            ->firstOrFail();

        $this->authorizeOrder($request, $order);

        return response()->json(['order' => $this->present($order)]);
    }

    /**
     * Raise the order for a closed auction's winner. Idempotent per
     * auction/lot so a retry does not create a duplicate.
     */
    public function store(Request $request): JsonResponse
    {
        $input = $request->all();
        if (! isset($input['auction_id']) && isset($input['auction_code'])) {
            $input['auction_id'] = $input['auction_code'];
        }
        $request->merge($input);

        $data = $request->validate([
            'auction_id' => ['required'],
            'lot' => ['sometimes', 'nullable'],
        ]);

        $auction = Auction::where('code', $data['auction_id'])
            ->orWhere('id', $data['auction_id'])
            ->firstOrFail();

        abort_unless($auction->status === 'closed', 422, 'Orders are only raised for closed auctions.');
        abort_unless($auction->winner_vendor_id, 422, 'This auction has no winning bidder.');

        $winning = (float) $auction->final_price;
        $emd = (float) EmdTransaction::where('auction_id', $auction->id)
            ->where('vendor_id', $auction->winner_vendor_id)
            ->value('amount');

        $gst = $winning * config('scrapify.gst_pct') / 100;
        $tcs = $winning * config('scrapify.tcs_pct') / 100;
        $total = $winning + $gst + $tcs;

        $order = Order::firstOrCreate(
            ['auction_id' => $auction->id, 'lot_id' => null],
            [
                'vendor_id' => $auction->winner_vendor_id,
                'user_id' => $auction->winnerVendorUserId(),
                'winning_amount' => $winning,
                'emd_applied' => $emd,
                'gst_pct' => config('scrapify.gst_pct'),
                'gst_amount' => round($gst, 2),
                'tcs_pct' => config('scrapify.tcs_pct'),
                'tcs_amount' => round($tcs, 2),
                'total_amount' => round($total, 2),
                'balance_due' => round($total - $emd, 2),
                'status' => 'awaiting_payment',
                'payment_due_at' => now()->addHours((int) config('scrapify.payment_window_hours')),
                'handover_otp' => (string) random_int(100000, 999999),
            ],
        );

        return response()->json(['order' => $this->present($order->fresh(['auction', 'vendor']))], 201);
    }

    public function pay(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string', 'max:40'],
            // A repeated reference is a client retry, not a server fault —
            // answer 422 rather than letting the unique index throw a 500.
            'reference' => ['sometimes', 'nullable', 'string', 'max:60', 'unique:payments,reference'],
        ]);

        $order = Order::where('code', $code)->firstOrFail();
        $this->authorizeOrder($request, $order);

        abort_unless($order->status === 'awaiting_payment', 422, "This order is already {$order->status}.");

        $payment = Payment::create([
            'reference' => $data['reference'] ?? WalletService::reference('PAY'),
            'payable_type' => Order::class,
            'payable_id' => $order->id,
            'amount' => $order->balance_due,
            'method' => $data['method'],
            'status' => 'success',
            'paid_at' => now(),
        ]);

        // The winner's EMD is consumed by the settlement.
        EmdTransaction::where('auction_id', $order->auction_id)
            ->where('vendor_id', $order->vendor_id)
            ->where('status', 'locked')
            ->update(['status' => 'released', 'released_at' => now(), 'note' => 'Applied to order '.$order->code]);

        $order->update(['status' => 'paid', 'paid_at' => now(), 'balance_due' => 0]);

        return response()->json(['order' => $this->present($order->fresh()), 'payment' => $payment]);
    }

    public function schedulePickup(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'window_start' => ['required', 'date'],
            'window_end' => ['required', 'date', 'after:window_start'],
            'address_id' => ['sometimes', 'nullable', 'integer', 'exists:addresses,id'],
            'warehouse' => ['sometimes', 'nullable', 'string', 'max:180'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $order = Order::where('code', $code)->firstOrFail();
        $this->authorizeOrder($request, $order);

        $existing = $order->pickup;

        $pickup = OrderPickup::create(array_merge($data, [
            'order_id' => $order->id,
            'status' => $existing ? 'rescheduled' : 'scheduled',
        ]));

        $order->update(['status' => $order->status === 'paid' ? 'pickup_scheduled' : $order->status]);

        return response()->json(['pickup' => $pickup], 201);
    }

    public function recordWeighbridge(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'declared_kg' => ['required', 'numeric', 'min:0'],
            'actual_kg' => ['required', 'numeric', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $order = Order::where('code', $code)->firstOrFail();

        // Short weight is refunded pro rata against the winning bid.
        $shortfall = max(0, $data['declared_kg'] - $data['actual_kg']);
        $adjustment = $data['declared_kg'] > 0
            ? round((float) $order->winning_amount * $shortfall / $data['declared_kg'], 2)
            : 0.0;

        $record = $order->weighbridge()->create(array_merge($data, ['adjustment_amount' => $adjustment]));

        if ($adjustment > 0 && $order->user_id) {
            $this->wallets->credit(
                $this->wallets->forUser($order->user),
                'refund',
                $adjustment,
                ['note' => "Weighbridge shortfall — {$order->code}", 'method' => 'Bank Transfer'],
            );
        }

        return response()->json(['weighbridge' => $record], 201);
    }

    public function verifyHandover(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['otp' => ['required', 'string', 'size:6']]);
        $order = Order::where('code', $code)->firstOrFail();

        abort_unless($order->handover_otp === $data['otp'], 422, 'Handover OTP does not match.');

        $order->update(['status' => 'picked_up']);

        return response()->json(['message' => 'Handover verified.', 'order' => $this->present($order->fresh())]);
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless(
            $request->user()->hasPermission('orders.manage') || $order->vendor_id === $request->user()->vendor_id,
            403,
            'You may only access your own orders.',
        );
    }

    private function present(Order $order): array
    {
        return [
            'id' => $order->code,
            'auction_id' => $order->auction?->code,
            'lot_id' => $order->lot?->code,
            'title' => $order->auction?->title,
            'vendor_id' => $order->vendor?->code,
            'winning_amount_inr' => (float) $order->winning_amount,
            'emd_applied_inr' => (float) $order->emd_applied,
            'gst_amount_inr' => (float) $order->gst_amount,
            'tcs_amount_inr' => (float) $order->tcs_amount,
            'total_amount_inr' => (float) $order->total_amount,
            'balance_due_inr' => (float) $order->balance_due,
            'status' => $order->status,
            'payment_due_at' => $order->payment_due_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            // Only the buyer sees the handover code; staff must not read it out.
            'handover_otp' => $order->vendor_id === request()->user()?->vendor_id ? $order->handover_otp : null,
            'pickup' => $order->pickup ? [
                'window_start' => $order->pickup->window_start?->toIso8601String(),
                'window_end' => $order->pickup->window_end?->toIso8601String(),
                'warehouse' => $order->pickup->warehouse,
                'status' => $order->pickup->status,
            ] : null,
            'weighbridge' => $order->weighbridge ? [
                'declared_kg' => (float) $order->weighbridge->declared_kg,
                'actual_kg' => (float) $order->weighbridge->actual_kg,
                'adjustment_inr' => (float) $order->weighbridge->adjustment_amount,
            ] : null,
            'documents' => $order->documents->map(fn ($d) => [
                'type' => $d->type,
                'file_name' => $d->file_name,
            ]),
        ];
    }
}
