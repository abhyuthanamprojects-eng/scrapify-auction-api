<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(User::ROLES)],
            'organization_code' => ['sometimes', 'nullable', 'string', 'exists:organizations,code'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        $orgId = null;
        if ($orgCode = ($data['organization_code'] ?? null)) {
            $orgId = \App\Models\Organization::where('code', $orgCode)->value('id');
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'role' => $data['role'],
            'organization_id' => $orgId,
            'status' => $data['status'] ?? 'active',
        ]);

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
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
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
}
