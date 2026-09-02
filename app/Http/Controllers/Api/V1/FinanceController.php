<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmdTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;

class FinanceController extends Controller
{
    public function summary(): JsonResponse
    {
        $totalWalletBalance = (float) Wallet::sum('balance');
        $totalLockedEmd = (float) Wallet::sum('locked');

        $totalPaymentsReceived = (float) Payment::where('status', 'success')->sum('amount');
        $pendingPayments = (float) Payment::where('status', 'pending')->sum('amount');

        $emdLocked = (float) EmdTransaction::where('status', 'locked')->sum('amount');
        $emdReleased = (float) EmdTransaction::where('status', 'released')->sum('amount');
        $emdForfeited = (float) EmdTransaction::where('status', 'forfeited')->sum('amount');

        $ordersAwaitingPayment = Order::where('status', 'awaiting_payment')->count();
        $totalOutstandingBalance = (float) Order::where('status', 'awaiting_payment')->sum('balance_due');

        $recentTransactions = WalletTransaction::with('wallet.user')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'user' => $t->wallet?->user?->name,
                'type' => $t->type,
                'amount_inr' => (float) $t->amount,
                'balance_after_inr' => (float) $t->balance_after,
                'note' => $t->note,
                'at' => $t->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'summary' => [
                'total_wallet_balance_inr' => $totalWalletBalance,
                'total_locked_emd_inr' => $totalLockedEmd,
                'available_balance_inr' => $totalWalletBalance - $totalLockedEmd,
                'total_payments_received_inr' => $totalPaymentsReceived,
                'pending_payments_inr' => $pendingPayments,
                'emd_locked_inr' => $emdLocked,
                'emd_released_inr' => $emdReleased,
                'emd_forfeited_inr' => $emdForfeited,
                'orders_awaiting_payment' => $ordersAwaitingPayment,
                'outstanding_balance_inr' => $totalOutstandingBalance,
            ],
            'recent_transactions' => $recentTransactions,
        ]);
    }
}
