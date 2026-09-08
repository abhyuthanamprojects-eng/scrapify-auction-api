<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessVerification;
use App\Services\BusinessVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessVerificationController extends Controller
{
    public function status(Request $request, BusinessVerificationService $service): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $service->present($service->forUser($request->user()))]);
    }

    public function history(Request $request): JsonResponse
    {
        $verification = BusinessVerification::where('user_id', $request->user()->id)->first();
        abort_unless($verification, 404, 'Business verification has not started.');
        return response()->json(['success' => true, 'data' => $verification->providerRequests()->latest()->get(['id', 'provider', 'verification_type', 'provider_reference', 'status', 'error_code', 'started_at', 'completed_at', 'latency_ms', 'created_at'])]);
    }

    public function verifyGstin(Request $request, BusinessVerificationService $service): JsonResponse
    {
        $data = $request->validate(['gstin' => ['required', 'string', 'size:15'], 'business_name' => ['nullable', 'string', 'max:200']]);
        return response()->json(['success' => true, 'data' => $service->present($service->verifyGstin($request->user(), $data['gstin'], $data['business_name'] ?? null))]);
    }

    public function verifyBank(Request $request, BusinessVerificationService $service): JsonResponse
    {
        $data = $request->validate(['bank_account' => ['required', 'string', 'min:6', 'max:40'], 'bank_account_confirmation' => ['required', 'same:bank_account'], 'ifsc' => ['required', 'string', 'size:11'], 'name' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'digits_between:8,13']]);
        return response()->json(['success' => true, 'data' => $service->present($service->verifyBank($request->user(), $data['bank_account'], $data['ifsc'], $data['name'] ?? null, $data['phone'] ?? null))]);
    }

    public function reverify(Request $request): JsonResponse
    {
        $verification = BusinessVerification::where('user_id', $request->user()->id)->firstOrFail();
        $verification->update(['overall_kyb_status' => 'REVERIFICATION_REQUIRED', 'review_reason' => 'User requested reverification']);
        \App\Services\AuditLogger::write('KYB_REVERIFICATION_REQUESTED', 'business_verification', (string) $verification->id);
        return response()->json(['success' => true, 'data' => app(BusinessVerificationService::class)->present($verification->fresh())]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = BusinessVerification::with(['user:id,name,email,role', 'vendor:id,company_name'])->latest();
        foreach (['role_type', 'gstin_status', 'bank_verification_status', 'overall_kyb_status'] as $field) if ($value = $request->query($field)) $query->where($field, $value);
        if ($search = $request->query('search')) $query->where(fn ($q) => $q->where('gstin', 'like', "%{$search}%")->orWhere('bank_account_masked', 'like', "%{$search}%"));
        return response()->json(['success' => true, 'data' => $query->paginate((int) $request->query('per_page', 50))->through(fn ($row) => $this->adminData($row))]);
    }

    public function adminShow(int $id): JsonResponse
    {
        $verification = BusinessVerification::with(['user', 'vendor', 'providerRequests'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $this->adminData($verification) + ['history' => $verification->providerRequests->makeHidden(['normalized_response'])]]);
    }

    public function approve(Request $request, int $id, BusinessVerificationService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        return response()->json(['success' => true, 'data' => $service->present($service->approve(BusinessVerification::with('vendor')->findOrFail($id), $request->user(), $data['reason']))]);
    }

    public function reject(Request $request, int $id, BusinessVerificationService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        return response()->json(['success' => true, 'data' => $service->present($service->reject(BusinessVerification::with('vendor')->findOrFail($id), $request->user(), $data['reason']))]);
    }

    public function requestReverification(Request $request, int $id): JsonResponse
    {
        $verification = BusinessVerification::findOrFail($id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $verification->update(['overall_kyb_status' => 'REVERIFICATION_REQUIRED', 'review_reason' => $data['reason']]);
        \App\Services\AuditLogger::write('KYB_REVERIFICATION_REQUIRED', 'business_verification', (string) $id, ['reason' => $data['reason']]);
        app(\App\Services\NotificationService::class)->push($verification->user, 'KYB_REVERIFICATION_REQUIRED', 'Business reverification required', $data['reason'], ['verification_id' => $id], "kyb:{$id}:reverification");
        return response()->json(['success' => true, 'data' => app(BusinessVerificationService::class)->present($verification->fresh())]);
    }

    private function adminData(BusinessVerification $v): array
    {
        return app(BusinessVerificationService::class)->present($v) + ['user' => $v->user?->only(['id', 'name', 'email', 'role']), 'vendor' => $v->vendor?->only(['id', 'code', 'company_name'])];
    }
}
