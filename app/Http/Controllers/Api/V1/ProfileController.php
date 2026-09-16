<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Address;
use App\Models\Award;
use App\Models\Auction;
use App\Models\Dispute;
use App\Models\EmdTransaction;
use App\Models\FallbackOffer;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Rules\IndianMobileNumber;
use App\Rules\IndianPincode;
use App\Services\AuditLogger;
use App\Services\PincodeLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Profile, addresses and payment methods — the mobile More tab.
 * No card numbers or bank credentials are accepted here; the client sends a
 * masked display label only.
 */
class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'string', 'max:20', new IndianMobileNumber(), Rule::unique('users', 'phone')->ignore($user->id)],
        ]);

        $user->update($data);

        return response()->json(['user' => new UserResource($user->fresh(['vendor', 'organization']))]);
    }

    public function addresses(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->addresses()->orderByDesc('is_default')->get()]);
    }

    public function storeAddress(Request $request, PincodeLookupService $pincodeService): JsonResponse
    {
        $data = $this->addressRules($request);
        $this->validateAddressLocation($data, $pincodeService);
        $address = $request->user()->addresses()->create($data);

        if ($address->is_default) {
            $this->clearOtherDefaults($request, $address->id);
        }

        return response()->json(['address' => $address], 201);
    }

    public function updateAddress(Request $request, int $id, PincodeLookupService $pincodeService): JsonResponse
    {
        $address = $request->user()->addresses()->findOrFail($id);
        $data = $this->addressRules($request, partial: true);
        $this->validateAddressLocation(array_merge($address->only(['city', 'state', 'pincode']), $data), $pincodeService);
        $address->update($data);

        if ($address->is_default) {
            $this->clearOtherDefaults($request, $address->id);
        }

        return response()->json(['address' => $address->fresh()]);
    }

    public function destroyAddress(Request $request, int $id): JsonResponse
    {
        $request->user()->addresses()->findOrFail($id)->delete();

        return response()->json(['message' => 'Address deleted.']);
    }

    public function paymentMethods(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->paymentMethods()->orderByDesc('is_primary')->get()]);
    }

    public function storePaymentMethod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['UPI', 'Card', 'Bank'])],
            'label' => ['required', 'string', 'max:60'],   // masked display value only
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:80'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $method = $request->user()->paymentMethods()->create($data);

        if ($method->is_primary) {
            PaymentMethod::where('user_id', $request->user()->id)
                ->where('id', '!=', $method->id)
                ->update(['is_primary' => false]);
        }

        return response()->json(['payment_method' => $method], 201);
    }

    public function destroyPaymentMethod(Request $request, int $id): JsonResponse
    {
        $request->user()->paymentMethods()->findOrFail($id)->delete();

        return response()->json(['message' => 'Payment method removed.']);
    }

    public function deletionCheck(Request $request): JsonResponse
    {
        $user = $request->user();
        $blockers = $this->accountDeletionBlockers($user);

        return response()->json([
            'can_delete' => $blockers === [],
            'blockers' => $blockers,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'confirmation' => ['required', 'in:DELETE'],
        ]);

        abort_if($user->isAdmin(), 422, 'Admin accounts cannot be deleted from this endpoint.');

        $blockers = $this->accountDeletionBlockers($user);
        if ($blockers !== []) {
            return response()->json([
                'message' => 'Account cannot be deleted.',
                'blockers' => $blockers,
            ], 422);
        }

        $deletedEmail = $user->email;
        $deletedId = $user->id;

        DB::transaction(function () use ($user): void {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            if ($user->vendor) {
                foreach ($user->vendor->documents as $doc) {
                    if ($doc->file_path) {
                        Storage::disk('public')->delete($doc->file_path);
                    }
                }
                $user->vendor->delete();
            }
            $user->tokens()->delete();
            $user->delete();
        });

        AuditLogger::writeFor($user, "Self-service account deletion: {$deletedEmail}", 'User', (string) $deletedId);

        return response()->json(['message' => 'Your account has been permanently deleted.']);
    }

    private function accountDeletionBlockers($user): array
    {
        $blockers = [];
        $vendorId = $user->vendor_id;

        // 1. Locked EMD deposits
        if ($vendorId) {
            $lockedEmd = EmdTransaction::where('vendor_id', $vendorId)
                ->where('status', 'locked')
                ->sum('amount');
            if ($lockedEmd > 0) {
                $blockers[] = [
                    'code' => 'emd_locked',
                    'message' => "You have ₹{$lockedEmd} in locked EMD deposits. These must be released before you can delete your account.",
                ];
            }
        }

        // 2. Non-zero wallet balance
        $wallet = $user->wallet;
        if ($wallet && (float) $wallet->balance > 0) {
            $blockers[] = [
                'code' => 'wallet_balance',
                'message' => "You have ₹{$wallet->balance} in your wallet. Please withdraw your balance before deleting your account.",
            ];
        }

        // 3. Active auctions (sellers)
        if ($user->role === 'seller' && $user->organization_id) {
            $activeAuctions = Auction::where('organization_id', $user->organization_id)
                ->whereIn('status', ['draft', 'submitted', 'approved', 'published', 'live'])
                ->count();
            if ($activeAuctions > 0) {
                $blockers[] = [
                    'code' => 'active_auctions',
                    'message' => "You have {$activeAuctions} active auction(s). Please close or cancel them before deleting your account.",
                ];
            }
        }

        // 4. Pending orders
        if ($vendorId) {
            $pendingOrders = Order::where('vendor_id', $vendorId)
                ->whereNotIn('status', ['completed', 'cancelled', 'closed'])
                ->count();
            if ($pendingOrders > 0) {
                $blockers[] = [
                    'code' => 'pending_orders',
                    'message' => "You have {$pendingOrders} unsettled order(s). Please complete or resolve them before deleting your account.",
                ];
            }
        }

        // 5. Open disputes
        $openDisputes = Dispute::where('raised_by_user_id', $user->id)
            ->whereNotIn('status', ['resolved', 'closed', 'withdrawn'])
            ->count();
        if ($openDisputes > 0) {
            $blockers[] = [
                'code' => 'open_disputes',
                'message' => "You have {$openDisputes} open dispute(s). Please resolve them before deleting your account.",
            ];
        }

        // 6. Pending awards (won but not yet settled/accepted)
        if ($vendorId) {
            $pendingAwards = Award::where('winner_vendor_id', $vendorId)
                ->whereNotIn('status', ['settled', 'cancelled', 'rejected', 'expired'])
                ->count();
            if ($pendingAwards > 0) {
                $blockers[] = [
                    'code' => 'pending_awards',
                    'message' => "You have {$pendingAwards} pending award(s). Please accept or resolve them before deleting your account.",
                ];
            }
        }

        // 7. Pending fallback offers
        if ($vendorId) {
            $pendingFallbacks = FallbackOffer::where('vendor_id', $vendorId)
                ->where('status', 'pending')
                ->count();
            if ($pendingFallbacks > 0) {
                $blockers[] = [
                    'code' => 'pending_fallback',
                    'message' => "You have {$pendingFallbacks} pending fallback offer(s). Please respond to them before deleting your account.",
                ];
            }
        }

        return $blockers;
    }

    private function addressRules(Request $request, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'name' => [$r, 'string', 'max:120'],
            'line' => [$r, 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'state' => ['sometimes', 'nullable', 'string', 'max:80'],
            'pincode' => ['sometimes', 'nullable', 'string', 'size:6', new IndianPincode()],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', new IndianMobileNumber()],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    private function clearOtherDefaults(Request $request, int $keepId): void
    {
        Address::where('user_id', $request->user()->id)
            ->where('id', '!=', $keepId)
            ->update(['is_default' => false]);
    }

    private function validateAddressLocation(array $data, PincodeLookupService $pincodeService): void
    {
        if (blank($data['pincode'] ?? null)) {
            return;
        }

        if (blank($data['city'] ?? null) || blank($data['state'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'city' => 'City and state are required when a PIN code is provided.',
            ]);
        }

        if (! $pincodeService->matches((string) $data['pincode'], $data['city'], $data['state'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'city' => 'City does not match the selected PIN code.',
                'state' => 'State does not match the selected PIN code.',
            ]);
        }
    }
}
