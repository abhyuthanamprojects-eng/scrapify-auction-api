<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Order;
use App\Models\Vendor;
use App\Services\AuditLogger;
use App\Services\RazorpayPaymentService;
use App\Services\RegistrationPricingService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RazorpayController extends Controller
{
    public function __construct(
        private RazorpayPaymentService $razorpay,
        private WalletService $wallets,
    ) {}

    public function createOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'purpose' => ['required', Rule::in(['wallet_topup', 'order_payment', 'registration'])],
            'order_code' => ['required_if:purpose,order_payment', 'nullable', 'string'],
            'vendor_code' => ['required_if:purpose,registration', 'nullable', 'string'],
            'promo_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'notes' => ['sometimes', 'array'],
        ]);

        $currency = $data['currency'] ?? 'INR';

        if ($data['purpose'] === 'registration') {
            $vendor = Vendor::where('code', $data['vendor_code'])->firstOrFail();
            abort_unless($request->user()->vendor_id === $vendor->id || $request->user()->isAdmin(), 403);
            $pricing = app(RegistrationPricingService::class)->quoteForVendor($vendor, $data['promo_code'] ?? null);
            $data['amount'] = $pricing['payable_amount'];
        }

        $amountPaise = (int) round((float) $data['amount'] * 100);

        $receipt = match ($data['purpose']) {
            'wallet_topup' => 'wlt_' . $request->user()->id . '_' . now()->format('YmdHis'),
            'order_payment' => 'ord_' . ($data['order_code'] ?? '') . '_' . now()->format('YmdHis'),
            'registration' => 'reg_' . $request->user()->id . '_' . now()->format('YmdHis'),
        };

        try {
            $result = $this->razorpay->createOrder($amountPaise, $currency, $receipt, array_merge(
                $data['notes'] ?? [],
                [
                    'user_id' => (string) $request->user()->id,
                    'purpose' => $data['purpose'],
                    ...($data['purpose'] === 'registration' ? ['vendor_code' => $data['vendor_code']] : []),
                    ...($data['purpose'] === 'registration' && filled($data['promo_code'] ?? null) ? ['promo_code' => strtoupper(trim($data['promo_code']))] : []),
                ],
            ));

            AuditLogger::write('RAZORPAY_ORDER_CREATED', 'payment', 'razorpay', [
                'razorpay_order_id' => $result['razorpay_order_id'],
                'amount_paise' => $amountPaise,
                'purpose' => $data['purpose'],
            ]);

            return response()->json([
                'data' => [
                    'razorpay_order_id' => $result['razorpay_order_id'],
                    'amount' => $result['amount'],
                    'currency' => $result['currency'],
                    'key_id' => $result['key_id'],
                    'purpose' => $data['purpose'],
                    'prefill' => [
                        'name' => $request->user()->name ?? '',
                        'email' => $request->user()->email ?? '',
                        'contact' => $request->user()->phone ?? '',
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            AuditLogger::write('RAZORPAY_ORDER_FAILED', 'payment', 'razorpay', [
                'error' => $e->getMessage(),
                'purpose' => $data['purpose'],
            ]);

            return response()->json([
                'error' => ['code' => 'RAZORPAY_ORDER_FAILED', 'message' => $e->getMessage()],
            ], 502);
        }
    }

    public function verifyPayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
            'purpose' => ['required', Rule::in(['wallet_topup', 'order_payment', 'registration'])],
            'order_code' => ['required_if:purpose,order_payment', 'nullable', 'string'],
            'vendor_code' => ['required_if:purpose,registration', 'nullable', 'string'],
            'promo_code' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        if (! $this->razorpay->verifySignature($data['razorpay_order_id'], $data['razorpay_payment_id'], $data['razorpay_signature'])) {
            AuditLogger::write('RAZORPAY_SIGNATURE_MISMATCH', 'payment', 'razorpay', [
                'razorpay_order_id' => $data['razorpay_order_id'],
                'razorpay_payment_id' => $data['razorpay_payment_id'],
            ]);

            return response()->json([
                'error' => ['code' => 'RAZORPAY_SIGNATURE_MISMATCH', 'message' => 'Payment verification failed. Signature mismatch.'],
            ], 400);
        }

        $paymentDetails = [];
        try {
            $paymentDetails = $this->razorpay->fetchPayment($data['razorpay_payment_id']);
        } catch (\Throwable $e) {
            // Non-fatal — signature is already verified
        }

        $amountInr = isset($paymentDetails['amount']) ? (float) $paymentDetails['amount'] / 100 : 0;
        $method = $paymentDetails['method'] ?? 'razorpay';

        $payment = Payment::create([
            'reference' => $data['razorpay_payment_id'],
            'payable_type' => $data['purpose'],
            'payable_id' => $request->user()->id,
            'amount' => $amountInr,
            'method' => $method,
            'gateway' => 'razorpay',
            'status' => 'success',
            'paid_at' => now(),
            'meta' => [
                'razorpay_order_id' => $data['razorpay_order_id'],
                'razorpay_signature' => $data['razorpay_signature'],
                'purpose' => $data['purpose'],
                ...($data['purpose'] === 'registration' && filled($data['promo_code'] ?? null)
                    ? ['promo_code' => strtoupper(trim($data['promo_code']))]
                    : []),
            ],
        ]);

        $result = ['payment_id' => $payment->id, 'status' => 'success', 'amount_inr' => $amountInr];

        if ($data['purpose'] === 'registration') {
            $vendor = Vendor::where('code', $data['vendor_code'])->firstOrFail();
            abort_unless($request->user()->vendor_id === $vendor->id || $request->user()->isAdmin(), 403);
            $payment->update([
                'payable_type' => Vendor::class,
                'payable_id' => $vendor->id,
            ]);
            $vendor->update([
                'registration_step' => 4,
                'registration_payment_method' => 'Razorpay',
                'registration_payment_ref' => $data['razorpay_payment_id'],
                'registration_payment_status' => 'success',
            ]);
            if (filled($data['promo_code'] ?? null)) {
                $promotion = \App\Models\RegistrationPromotion::query()
                    ->where('code', strtoupper(trim($data['promo_code'])))
                    ->lockForUpdate()
                    ->first();
                if ($promotion) {
                    $promotion->increment('redemption_count');
                }
            }
            $result['vendor_code'] = $vendor->code;
        }

        if ($data['purpose'] === 'wallet_topup' && $amountInr > 0) {
            $wallet = $this->wallets->forUser($request->user());
            $txn = $this->wallets->credit($wallet, 'add_money', $amountInr, [
                'method' => 'Razorpay - ' . ucfirst($method),
                'note' => 'Razorpay payment ' . $data['razorpay_payment_id'],
            ]);
            $result['balance_inr'] = (float) $wallet->fresh()->balance;
            $result['transaction_id'] = $txn->id;
        }

        if ($data['purpose'] === 'order_payment' && filled($data['order_code'])) {
            $order = Order::where('code', $data['order_code'])->first();
            if ($order && $order->status === 'awaiting_payment') {
                $order->update(['status' => 'paid', 'paid_at' => now(), 'balance_due' => 0]);
                $payment->update(['payable_type' => Order::class, 'payable_id' => $order->id]);
                $result['order_status'] = 'paid';
            }
        }

        AuditLogger::write('RAZORPAY_PAYMENT_VERIFIED', 'payment', 'razorpay', [
            'razorpay_order_id' => $data['razorpay_order_id'],
            'razorpay_payment_id' => $data['razorpay_payment_id'],
            'amount_inr' => $amountInr,
            'purpose' => $data['purpose'],
        ]);

        return response()->json(['data' => $result]);
    }
}
