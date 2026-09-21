<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Auction;
use App\Models\Order;
use App\Models\Vendor;
use App\Services\EmdService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManualPaymentController extends Controller
{
    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'in:wallet_topup,emd,order_payment'],
            'amount' => ['required', 'numeric', 'min:1'],
            'transaction_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'target_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $path = $request->file('proof')->store('manual-payments/'.$request->user()->id);
        $payment = Payment::create([
            'reference' => 'BANK-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6)),
            'payable_type' => get_class($request->user()),
            'payable_id' => $request->user()->id,
            'amount' => $data['amount'],
            'method' => 'Bank Transfer',
            'gateway' => 'manual_bank_transfer',
            'status' => 'pending',
            'meta' => ['purpose' => $data['purpose'], 'target_code' => $data['target_code'] ?? null, 'transaction_id' => $data['transaction_id'] ?? null, 'proof_path' => $path, 'submitted_at' => now()->toIso8601String()],
        ]);

        return response()->json(['message' => 'Bank proof submitted for admin verification.', 'payment' => $this->present($payment)], 201);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        $payment = Payment::whereKey($id)->firstOrFail();
        abort_unless($payment->gateway === 'manual_bank_transfer' && $payment->payable_type === get_class($request->user()) && (int) $payment->payable_id === (int) $request->user()->id, 403);
        abort_unless($payment->status === 'verified', 422, 'Payment is not approved by admin yet.');
        $meta = $payment->meta ?? [];
        abort_unless(hash_equals((string) ($meta['verification_reference'] ?? ''), (string) $request->input('verification_reference')), 422, 'Invalid payment verification reference.');

        DB::transaction(function () use ($payment, $meta, $request) {
            $meta['user_confirmed_at'] = now()->toIso8601String();
            if (in_array($meta['purpose'] ?? null, ['wallet_topup', 'emd'], true) && empty($meta['wallet_credited_at'])) {
                app(WalletService::class)->credit(app(WalletService::class)->forUser($request->user()), 'add_money', (float) $payment->amount, ['method' => 'Bank Transfer', 'reference' => $payment->reference, 'note' => 'Admin-approved bank transfer']);
                $meta['wallet_credited_at'] = now()->toIso8601String();
            }
            if (($meta['purpose'] ?? null) === 'emd' && empty($meta['emd_locked_at'])) {
                $auction = Auction::where('code', $meta['target_code'] ?? '')->firstOrFail();
                // Resolve the profile directly so a relation cached on the
                // authenticated user cannot make a valid EMD confirmation fail.
                $vendor = Vendor::where('user_id', $request->user()->id)->first();
                abort_unless($vendor, 422, 'This account has no vendor profile.');
                app(EmdService::class)->ensureLocked($auction, $vendor);
                $meta['emd_locked_at'] = now()->toIso8601String();
            }
            if (($meta['purpose'] ?? null) === 'order_payment' && empty($meta['order_paid_at'])) {
                $order = Order::where('code', $meta['target_code'] ?? '')->firstOrFail();
                abort_unless((int) $order->user_id === (int) $request->user()->id, 403);
                $order->update(['status' => 'paid', 'paid_at' => now(), 'balance_due' => 0]);
                $meta['order_paid_at'] = now()->toIso8601String();
            }
            $payment->update(['status' => 'success', 'paid_at' => now(), 'meta' => $meta]);
        });
        return response()->json(['message' => 'Payment reference confirmed.', 'payment' => $this->present($payment->fresh())]);
    }

    public function index(Request $request): JsonResponse
    {
        $page = Payment::where('gateway', 'manual_bank_transfer')->latest('id')->paginate((int) $request->query('per_page', 30));
        return response()->json(['data' => collect($page->items())->map(fn (Payment $p) => $this->present($p)), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function verify(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:verified,rejected'], 'reason' => ['sometimes', 'nullable', 'string', 'max:500']]);
        $payment = Payment::whereKey($id)->where('gateway', 'manual_bank_transfer')->firstOrFail();
        $meta = $payment->meta ?? [];
        if ($data['status'] === 'verified') {
            $meta['verification_reference'] = 'SCRAPIFY-BANK-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
            $meta['verified_at'] = now()->toIso8601String();
        } else {
            $meta['rejection_reason'] = $data['reason'] ?? 'Payment proof rejected.';
        }
        $payment->update(['status' => $data['status'], 'meta' => $meta]);
        return response()->json(['message' => 'Payment review saved.', 'payment' => $this->present($payment->fresh())]);
    }

    private function present(Payment $payment): array
    {
        $meta = $payment->meta ?? [];
        return ['id' => $payment->id, 'amount_inr' => (float) $payment->amount, 'method' => $payment->method, 'status' => $payment->status, 'reference' => $payment->reference, 'purpose' => $meta['purpose'] ?? null, 'target_code' => $meta['target_code'] ?? null, 'transaction_id' => $meta['transaction_id'] ?? null, 'verification_reference' => $meta['verification_reference'] ?? null, 'paid_at' => $payment->paid_at?->toIso8601String()];
    }
}
