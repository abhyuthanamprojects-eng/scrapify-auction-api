<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RegistrationPromotion;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegistrationPromotionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['promotions' => RegistrationPromotion::query()->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $promotion = RegistrationPromotion::create($this->validated($request));
        AuditLogger::write('Created registration promotion', 'RegistrationPromotion', (string) $promotion->id, ['code' => $promotion->code]);
        return response()->json(['promotion' => $promotion], 201);
    }

    public function update(Request $request, RegistrationPromotion $promotion): JsonResponse
    {
        $promotion->update($this->validated($request, $promotion));
        AuditLogger::write('Updated registration promotion', 'RegistrationPromotion', (string) $promotion->id, ['code' => $promotion->code]);
        return response()->json(['promotion' => $promotion->fresh()]);
    }

    public function destroy(RegistrationPromotion $promotion): JsonResponse
    {
        $promotion->delete();
        AuditLogger::write('Deleted registration promotion', 'RegistrationPromotion', (string) $promotion->id);
        return response()->json(['success' => true]);
    }

    private function validated(Request $request, ?RegistrationPromotion $promotion = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9 _-]*$/', Rule::unique('registration_promotions', 'code')->ignore($promotion?->id)],
            'discount_type' => ['required', Rule::in(['fixed', 'percentage'])],
            'discount_value' => ['required', 'numeric', 'gt:0'],
            'minimum_amount' => ['sometimes', 'numeric', 'min:0'],
            'maximum_discount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
            'active' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $data['code'] = strtoupper(trim((string) $data['code']));
        return $data;
    }
}
