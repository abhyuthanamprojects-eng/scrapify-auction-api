<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\EmdTransaction;
use App\Models\Lot;
use App\Services\EmdService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WalletController extends Controller
{
    public function __construct(private WalletService $wallets, private EmdService $emd)
    {
    }

    public function balance(Request $request): JsonResponse
    {
        $wallet = $this->wallets->forUser($request->user());

        return response()->json([
            'balance_inr' => (float) $wallet->balance,
            'locked_inr' => (float) $wallet->locked,
            'available_inr' => $wallet->available(),
            'currency' => $wallet->currency,
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $wallet = $this->wallets->forUser($request->user());
        $q = $wallet->transactions();

        if ($type = $request->query('type')) {
            $q->whereIn('type', array_map('trim', explode(',', $type)));
        }

        $page = $q->paginate((int) $request->query('per_page', 30));

        return response()->json([
            'data' => collect($page->items())->map(fn ($t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount_inr' => (float) $t->amount,   // signed: credit positive, debit negative
                'balance_after_inr' => $t->balance_after !== null ? (float) $t->balance_after : null,
                'note' => $t->note,
                'method' => $t->method,
                'status' => $t->status,
                'reference' => $t->reference,
                'at' => $t->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * Add money. No gateway is wired in this pass — the caller supplies the
     * method and reference, and the ledger records it as a successful credit.
     */
    public function topUp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'method' => ['required', Rule::in(['UPI', 'Card', 'Net Banking', 'Bank Transfer'])],
            'note' => ['sometimes', 'nullable', 'string', 'max:180'],
        ]);

        $wallet = $this->wallets->forUser($request->user());

        $txn = $this->wallets->credit($wallet, 'add_money', (float) $data['amount'], [
            'method' => $data['method'],
            'note' => $data['note'] ?? $data['method'],
        ]);

        return response()->json([
            'transaction' => $txn,
            'balance_inr' => (float) $wallet->fresh()->balance,
        ], 201);
    }

    public function lockEmd(Request $request): JsonResponse
    {
        $data = $request->validate([
            'auction_id' => ['required', 'string', 'exists:auctions,code'],
            'lot' => ['sometimes', 'nullable', 'string', 'exists:lots,code'],
        ]);

        $auction = Auction::where('code', $data['auction_id'])->firstOrFail();
        $vendor = $request->user()->vendor;

        abort_unless($vendor, 422, 'This account has no vendor profile.');

        $emd = $this->emd->ensureLocked(
            $auction,
            $vendor,
            isset($data['lot']) ? Lot::where('code', $data['lot'])->value('id') : null,
        );

        return response()->json([
            'emd' => $emd,
            'wallet' => $this->balance($request)->getData(true),
        ], 201);
    }

    public function releaseEmd(Request $request, int $id): JsonResponse
    {
        $emd = EmdTransaction::findOrFail($id);
        $user = $request->user();

        abort_unless(
            $user->hasPermission('emd.manage') || $emd->vendor_id === $user->vendor_id,
            403,
            'You may only release your own EMD.',
        );

        // A bidder may release their own hold only once the auction is over.
        if (! $user->hasPermission('emd.manage')) {
            abort_unless(
                in_array($emd->auction->status, ['closed', 'cancelled'], true),
                422,
                'EMD stays locked until the auction closes.',
            );
        }

        $this->emd->release($emd, $request->input('reason', 'Released'));

        return response()->json(['emd' => $emd->fresh()]);
    }

    public function forfeitEmd(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $this->emd->forfeit(EmdTransaction::findOrFail($id), $data['reason']);

        return response()->json(['emd' => EmdTransaction::find($id)]);
    }

    public function emdList(Request $request): JsonResponse
    {
        $q = EmdTransaction::query()->with(['auction', 'vendor']);

        if (! $request->user()->hasPermission('emd.manage')) {
            $q->where('vendor_id', $request->user()->vendor_id);
        }

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        if ($auctionCode = $request->query('auction')) {
            $q->whereHas('auction', fn ($a) => $a->where('code', $auctionCode));
        }

        return response()->json([
            'data' => $q->latest('id')->get()->map(fn (EmdTransaction $e) => [
                'id' => $e->id,
                'auction_id' => $e->auction?->code,
                'vendor_id' => $e->vendor?->code,
                'vendor_name' => $e->vendor?->company_name,
                'amount_inr' => (float) $e->amount,
                'status' => $e->status,
                'reference' => $e->reference,
                'locked_at' => $e->locked_at?->toIso8601String(),
                'released_at' => $e->released_at?->toIso8601String(),
            ]),
        ]);
    }
}
