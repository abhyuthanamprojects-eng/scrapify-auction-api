<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Organization::query()->with(['units', 'documents']);

        if ($status = $request->query('status')) {
            $q->whereIn('status', array_map('trim', explode(',', $status)));
        }

        if ($search = $request->query('search')) {
            $q->where(fn ($w) => $w->where('code', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%"));
        }

        return OrganizationResource::collection(
            $q->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25)),
        );
    }

    public function show(string $code): OrganizationResource
    {
        return new OrganizationResource(
            Organization::where('code', $code)
                ->with(['units', 'documents', 'plants.warehouses'])
                ->firstOrFail(),
        );
    }

    /** Client Interface Creation flow: company + N units, each with GST and bank. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->rules($request);

        $org = Organization::create([
            'company_name' => $data['company_name'],
            'location' => $data['location'],
            'total_units' => $data['total_units'],
            'status' => $data['status'] ?? 'draft',
            'bank_account_number' => $data['bank']['account_number'] ?? null,
            'bank_ifsc' => $data['bank']['ifsc'] ?? null,
            'bank_name' => $data['bank']['bank_name'] ?? null,
            'created_by' => $request->user()->id,
            'submitted_at' => ($data['status'] ?? 'draft') === 'pending_super_admin_approval' ? now() : null,
        ]);

        $this->syncUnits($org, $data['units'] ?? []);
        $this->syncDocuments($org, $data['documents'] ?? []);

        return (new OrganizationResource($org->load(['units', 'documents'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, string $code): OrganizationResource
    {
        $org = Organization::where('code', $code)->firstOrFail();

        abort_if($org->status === 'approved', 422, 'An approved organization cannot be edited.');

        $data = $this->rules($request, partial: true);

        $org->update(array_filter([
            'company_name' => $data['company_name'] ?? null,
            'location' => $data['location'] ?? null,
            'total_units' => $data['total_units'] ?? null,
            'bank_account_number' => $data['bank']['account_number'] ?? null,
            'bank_ifsc' => $data['bank']['ifsc'] ?? null,
            'bank_name' => $data['bank']['bank_name'] ?? null,
        ], fn ($v) => $v !== null));

        if (array_key_exists('units', $data)) {
            $org->units()->delete();
            $this->syncUnits($org, $data['units']);
        }

        if (array_key_exists('documents', $data)) {
            $this->syncDocuments($org, $data['documents']);
        }

        return new OrganizationResource($org->fresh(['units', 'documents']));
    }

    public function submit(string $code): OrganizationResource
    {
        $org = Organization::where('code', $code)->with('units')->firstOrFail();

        abort_unless($org->status === 'draft', 422, 'Only a draft organization can be submitted.');
        abort_if($org->units->count() !== $org->total_units, 422, 'Every unit must be filled in before submitting.');

        $org->update(['status' => 'pending_super_admin_approval', 'submitted_at' => now()]);

        return new OrganizationResource($org);
    }

    public function approve(Request $request, string $code): OrganizationResource
    {
        $org = Organization::where('code', $code)->firstOrFail();

        abort_unless(
            $org->status === 'pending_super_admin_approval',
            422,
            'This organization is not awaiting approval.',
        );

        $org->update([
            'status' => 'approved',
            'rejection_reason' => null,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return new OrganizationResource($org);
    }

    public function reject(Request $request, string $code): OrganizationResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $org = Organization::where('code', $code)->firstOrFail();

        $org->update([
            'status' => 'rejected',
            'rejection_reason' => $data['reason'],
            'approved_by' => $request->user()->id,
        ]);

        return new OrganizationResource($org);
    }

    private function rules(Request $request, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'company_name' => [$r, 'string', 'max:180'],
            'location' => [$r, 'string', 'max:255'],
            'total_units' => [$r, 'integer', 'min:0', 'max:50'],
            'status' => ['sometimes', 'in:draft,pending_super_admin_approval'],
            'bank.account_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'bank.ifsc' => ['sometimes', 'nullable', 'string', 'max:20'],
            'bank.bank_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'units' => ['sometimes', 'array'],
            'units.*.name' => ['required_with:units', 'string', 'max:180'],
            'units.*.gst' => ['required_with:units', 'string', 'max:20'],
            'units.*.location' => ['required_with:units', 'string', 'max:180'],
            'units.*.bank.account_number' => ['required_with:units', 'string', 'max:40'],
            'units.*.bank.ifsc' => ['required_with:units', 'string', 'max:20'],
            'units.*.bank.bank_name' => ['required_with:units', 'string', 'max:120'],
            'documents' => ['sometimes', 'array'],
            'documents.*.type' => ['required_with:documents', 'string', 'max:80'],
            'documents.*.file_name' => ['required_with:documents', 'string', 'max:255'],
        ]);
    }

    private function syncUnits(Organization $org, array $units): void
    {
        foreach (array_values($units) as $i => $unit) {
            $org->units()->create([
                'code' => 'U-'.($i + 1),
                'name' => $unit['name'],
                'gst' => $unit['gst'],
                'location' => $unit['location'],
                'bank_account_number' => $unit['bank']['account_number'],
                'bank_ifsc' => $unit['bank']['ifsc'],
                'bank_name' => $unit['bank']['bank_name'],
            ]);
        }
    }

    private function syncDocuments(Organization $org, array $documents): void
    {
        foreach (array_values($documents) as $i => $doc) {
            $org->documents()->create([
                'code' => 'D-'.($i + 1),
                'type' => $doc['type'],
                'file_name' => $doc['file_name'],
                'uploaded_at' => now(),
            ]);
        }
    }
}
