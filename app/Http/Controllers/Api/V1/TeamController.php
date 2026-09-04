<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TeamController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $q = User::query()->with(['organization', 'vendor']);

        if ($user->organization_id) {
            $q->where('organization_id', $user->organization_id);
        } elseif ($user->vendor_id) {
            $q->where('vendor_id', $user->vendor_id);
        }

        if ($search = $request->query('search')) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($role = $request->query('role')) {
            $q->where('role', $role);
        }

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        return UserResource::collection(
            $q->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $roleMap = [
            'authorizedBidder' => 'buyer',
            'authorized_bidder' => 'buyer',
            'vendorAdmin' => 'seller',
            'vendor_admin' => 'seller',
            'fieldInspector' => 'technical_evaluator',
            'field_inspector' => 'technical_evaluator',
            'financeApprover' => 'finance_manager',
            'finance_approver' => 'finance_manager',
            'admin' => 'admin',
            'buyer' => 'buyer',
            'seller' => 'seller',
            'procurement_manager' => 'procurement_manager',
            'finance_manager' => 'finance_manager',
            'technical_evaluator' => 'technical_evaluator',
            'auditor' => 'auditor',
        ];

        if ($reqRole = $request->input('role')) {
            if (isset($roleMap[$reqRole])) {
                $request->merge(['role' => $roleMap[$reqRole]]);
            }
        }

        if ($phone = ($request->input('phone') ?? $request->input('mobile'))) {
            $request->merge(['phone' => $phone]);
        }

        if (!$request->filled('password')) {
            $request->merge(['password' => 'password123']);
        }

        if ($request->has('is_active')) {
            $request->merge(['status' => $request->boolean('is_active') ? 'active' : 'inactive']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'password' => ['sometimes', 'nullable', 'string', 'min:6'],
            'role' => ['required', Rule::in(User::ROLES)],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        $creator = $request->user();

        $member = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'role' => $data['role'],
            'organization_id' => $creator->organization_id,
            'vendor_id' => $creator->vendor_id,
            'status' => $data['status'] ?? 'active',
        ]);

        return (new UserResource($member->load(['organization', 'vendor'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, string $id): UserResource
    {
        $user = $request->user();
        $member = User::where('id', $id)->orWhere('uuid', $id)->firstOrFail();

        abort_unless(
            $user->isAdmin()
                || ($user->organization_id && $member->organization_id === $user->organization_id)
                || ($user->vendor_id && $member->vendor_id === $user->vendor_id),
            403,
            'You may only manage members in your own organization.',
        );

        $roleMap = [
            'authorizedBidder' => 'buyer',
            'authorized_bidder' => 'buyer',
            'vendorAdmin' => 'seller',
            'vendor_admin' => 'seller',
            'fieldInspector' => 'technical_evaluator',
            'field_inspector' => 'technical_evaluator',
            'financeApprover' => 'finance_manager',
            'finance_approver' => 'finance_manager',
        ];

        if ($reqRole = $request->input('role')) {
            if (isset($roleMap[$reqRole])) {
                $request->merge(['role' => $roleMap[$reqRole]]);
            }
        }

        if ($phone = ($request->input('phone') ?? $request->input('mobile'))) {
            $request->merge(['phone' => $phone]);
        }

        if ($request->has('is_active')) {
            $request->merge(['status' => $request->boolean('is_active') ? 'active' : 'inactive']);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($member->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        $member->update($data);

        return new UserResource($member->fresh(['organization', 'vendor']));
    }
}
