<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AuditLogger;
use App\Rules\IndianMobileNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = User::query()->with(['organization', 'vendor', 'businessVerification']);

        if ($orgId = $request->query('organization_id')) {
            $org = \App\Models\Organization::where('code', $orgId)->first();
            if ($org) {
                $q->where('organization_id', $org->id);
            }
        }

        if ($role = $request->query('role')) {
            $q->whereIn('role', array_map('trim', explode(',', $role)));
        }

        if ($status = $request->query('status')) {
            if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
                $q->whereHas('vendor', fn ($v) => $v->where('status', $status));
            } elseif ($status === 'blocked') {
                $q->where('status', 'inactive');
            } elseif ($status === 'suspended') {
                $q->where(fn ($w) => $w->where('status', 'suspended')
                    ->orWhereHas('vendor', fn ($v) => $v->where('status', 'suspended')));
            } else {
                $q->where('status', $status);
            }
        }

        if ($approval = $request->query('approval_status')) {
            $q->whereHas('vendor', fn ($v) => $v->where('status', $approval));
        }
        if ($kyb = $request->query('kyb_status')) {
            if (strtoupper($kyb) === 'PENDING') {
                $q->whereIn('role', ['buyer', 'seller'])->where(fn ($w) =>
                    $w->whereDoesntHave('businessVerification')->orWhereHas('businessVerification',
                        fn ($v) => $v->whereIn('overall_kyb_status', ['NOT_STARTED', 'PENDING', 'IN_PROGRESS', 'REVIEW_REQUIRED', 'REVERIFICATION_REQUIRED'])));
            } else {
                $q->whereHas('businessVerification', fn ($v) => $v->where('overall_kyb_status', strtoupper($kyb)));
            }
        }

        if ($search = $request->query('search')) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhereHas('vendor', fn ($v) => $v->where('company_name', 'like', "%{$search}%")
                    ->orWhere('gst_number', 'like', "%{$search}%")));
        }

        return UserResource::collection(
            $q->orderByDesc('created_at')->paginate(max(1, min(100, (int) $request->query('per_page', 25)))),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', new IndianMobileNumber()],
            'password' => ['required', 'string', \Illuminate\Validation\Rules\Password::min(8)->mixedCase()->numbers()->symbols()],
            'role' => ['required', Rule::in(User::ROLES)],
            'organization_code' => ['sometimes', 'nullable', 'string', 'exists:organizations,code'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
            'business_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'business_type' => ['sometimes', 'nullable', 'string', 'max:60'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category' => ['sometimes', 'nullable', 'string', 'max:120'],
            'years_in_business' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'pincode' => ['sometimes', 'nullable', 'string', 'size:6'],
            'gst_number' => ['sometimes', 'nullable', 'string', 'max:15'],
            'pan_number' => ['sometimes', 'nullable', 'string', 'max:10'],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'account_holder_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'ifsc_code' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        $orgId = null;
        if ($orgCode = ($data['organization_code'] ?? null)) {
            $orgId = \App\Models\Organization::where('code', $orgCode)->value('id');
        }

        $user = DB::transaction(function () use ($data, $orgId): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'role' => $data['role'],
                'organization_id' => $orgId,
                'status' => $data['status'] ?? 'active',
                'email_verified_at' => now(),
                'phone_verified_at' => filled($data['phone'] ?? null) ? now() : null,
            ]);

            // A buyer/seller is also a vendor record. Without this record the
            // account can authenticate but is invisible to the Customers page.
            if (in_array($user->role, ['buyer', 'seller'], true)) {
                $city = trim((string) ($data['city'] ?? ''));
                $state = trim((string) ($data['state'] ?? ''));
                $location = trim(implode(', ', array_filter([$city, $state])));
                $vendor = Vendor::create([
                    'user_id' => $user->id,
                    'company_name' => $data['business_name'] ?? $user->name,
                    'trade_name' => $data['trade_name'] ?? null,
                    'business_type' => $data['business_type'] ?? null,
                    'contact_name' => $data['contact_person'] ?? $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone ?? '',
                    'location' => $location ?: null,
                    'address' => $data['address_line1'] ?? null,
                    'address_line1' => $data['address_line1'] ?? null,
                    'city' => $city ?: null,
                    'state' => $state ?: null,
                    'pincode' => $data['pincode'] ?? null,
                    'gst_number' => $data['gst_number'] ?? null,
                    'pan_number' => $data['pan_number'] ?? null,
                    'bank_name' => $data['bank_name'] ?? null,
                    'account_holder_name' => $data['account_holder_name'] ?? null,
                    'account_number' => $data['account_number'] ?? null,
                    'ifsc_code' => $data['ifsc_code'] ?? null,
                    'status' => 'pending',
                    'registration_step' => 5,
                ]);
                $user->update(['vendor_id' => $vendor->id]);
            }

            return $user;
        });

        return (new UserResource($user->load(['organization', 'vendor'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, string $id): UserResource
    {
        $user = User::where('uuid', $id)->when(ctype_digit($id), fn ($q) => $q->orWhere('id', $id))->firstOrFail();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', new IndianMobileNumber()],
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'organization_code' => ['sometimes', 'nullable', 'string', 'exists:organizations,code'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        $attrs = collect($data)->except(['organization_code'])->all();

        if (array_key_exists('organization_code', $data)) {
            $attrs['organization_id'] = $data['organization_code']
                ? \App\Models\Organization::where('code', $data['organization_code'])->value('id')
                : null;
        }

        $user->update($attrs);

        return new UserResource($user->fresh(['organization', 'vendor']));
    }

    /** Permanently remove a specifically confirmed non-admin testing account. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = User::with(['vendor.documents'])->where('uuid', $id)
            ->when(ctype_digit($id), fn ($q) => $q->orWhere('id', $id))
            ->firstOrFail();

        $request->validate([
            'confirmation' => ['required', 'in:DELETE '.$user->email],
        ]);

        abort_if($user->id === $request->user()->id, 422, 'You cannot delete your own account.');
        abort_if($user->isAdmin(), 422, 'Admin accounts cannot be deleted from this screen.');

        $deletedEmail = $user->email;
        DB::transaction(function () use ($user): void {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            if ($user->vendor) {
                foreach ($user->vendor->documents as $document) {
                    if ($document->file_path) {
                        Storage::disk('public')->delete($document->file_path);
                    }
                }
                $user->vendor->delete();
            }
            $user->delete();
        });

        AuditLogger::write("Deleted user and owned data: {$deletedEmail}", 'User', (string) $user->id);

        return response()->json(['success' => true, 'message' => 'User and all owned data were permanently deleted.']);
    }
}
