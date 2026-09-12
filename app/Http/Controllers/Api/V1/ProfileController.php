<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Address;
use App\Models\PaymentMethod;
use App\Rules\IndianMobileNumber;
use App\Rules\IndianPincode;
use App\Services\PincodeLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
