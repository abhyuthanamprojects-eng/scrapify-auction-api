<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VendorResource;
use App\Models\Category;
use App\Models\Payment;
use App\Models\Vendor;
use App\Models\VendorDocument;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = Vendor::query()->with(['materials', 'documents']);

        if ($status = $request->query('status')) {
            $q->whereIn('status', array_map('trim', explode(',', $status)));
        }

        if ($search = $request->query('search')) {
            $q->where(fn ($w) => $w->where('code', 'like', "%{$search}%")
                ->orWhere('company_name', 'like', "%{$search}%")
                ->orWhere('gst_number', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($category = $request->query('material')) {
            $q->whereHas('materials', fn ($c) => $c->where('slug', $category)->orWhere('name', $category));
        }

        return VendorResource::collection(
            $q->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25)),
        );
    }

    public function show(string $code): VendorResource
    {
        $vendor = Vendor::where('code', $code)->with(['materials', 'documents'])->firstOrFail();

        // Participation history, as the admin vendor detail screen shows it.
        $vendor->participation = $vendor->bids()
            ->with('auction')
            ->get()
            ->groupBy('auction_id')
            ->map(function ($bids) use ($vendor) {
                $auction = $bids->first()->auction;

                return [
                    'id' => $auction->code,
                    'auction' => "{$auction->code} — {$auction->title}",
                    'date' => $auction->closed_at?->toDateString() ?? $auction->created_at->toDateString(),
                    'bids' => $bids->count(),
                    'won' => $auction->winner_vendor_id === $vendor->id,
                    'amount_inr' => $auction->winner_vendor_id === $vendor->id ? (float) $auction->final_price : 0,
                ];
            })
            ->values();

        return new VendorResource($vendor);
    }

    /**
     * Vendor registration — step 3 of the mobile signup wizard. Callable by an
     * authenticated user completing their own profile, or by an admin
     * onboarding a vendor from the panel.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:180'],
            'location' => ['sometimes', 'nullable', 'string', 'max:180'],
            'address' => ['sometimes', 'nullable', 'string'],
            'contact_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email'],
            'phone' => ['required', 'string', 'max:20'],
            'gst_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'pan_number' => ['sometimes', 'nullable', 'string', 'max:15'],
            'license_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'material_interest' => ['sometimes', 'array'],
            'material_interest.*' => ['string'],
            'terms_accepted' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        $vendor = $user?->vendor ?? new Vendor(['user_id' => $user?->id]);
        $vendor->fill(collect($data)->except(['material_interest', 'terms_accepted'])->all());
        $vendor->registration_step = 3;
        $vendor->status = $vendor->status ?: 'pending';

        if ($data['terms_accepted'] ?? false) {
            $vendor->terms_accepted_at = now();
        }

        $vendor->save();

        if ($ids = $this->categoryIds($data['material_interest'] ?? [])) {
            $vendor->materials()->sync($ids);
        }

        $user?->update(['vendor_id' => $vendor->id]);

        return (new VendorResource($vendor->load(['materials', 'documents'])))
            ->response()
            ->setStatusCode(201);
    }

    /** KYC document upload. Files land in storage/app/public/kyc. */
    public function uploadDocument(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'doc_key' => ['required', 'string', 'max:30'],
            'kind' => ['required', 'string', 'max:60'],
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $vendor = Vendor::where('code', $code)->firstOrFail();
        $this->authorizeVendorAccess($request, $vendor);
        $path = $request->file('file')->store('kyc', 'public');
        $originalName = $request->file('file')->getClientOriginalName();
        $kind = $data['kind'];
        $doc = VendorDocument::updateOrCreate(
            ['vendor_id' => $vendor->id, 'doc_key' => $data['doc_key']],
            [
                'kind' => $data['kind'],
                'name' => $data['kind'],
                'file_name' => $originalName,
                'file_path' => $path,
                'size_kb' => (int) round($request->file('file')->getSize() / 1024),
                'status' => 'pending',
                'ocr_status' => 'pending',
                'ocr_confidence' => 0,
                'ocr_extracted_data' => null,
                'reason' => null,
                'approved_on' => null,
                'uploaded_at' => now(),
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Document uploaded and queued for verification.',
            'document' => $doc,
            'ocr' => ['status' => 'pending'],
        ], 201);
    }

    public function reviewDocument(Request $request, string $code, int $documentId): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected', 'pending'])],
            'reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:500'],
        ]);

        $vendor = Vendor::where('code', $code)->firstOrFail();
        $doc = $vendor->documents()->findOrFail($documentId);

        $doc->update([
            'status' => $data['status'],
            'reason' => $data['reason'] ?? null,
            'approved_on' => $data['status'] === 'approved' ? now() : null,
        ]);

        return response()->json(['document' => $doc]);
    }

    /**
     * Registration fee. Recorded only — no gateway is integrated in this pass;
     * finance verifies the RTGS/NEFT/UPI reference manually.
     */
    public function recordRegistrationPayment(Request $request, string $code): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['RTGS', 'NEFT', 'UPI'])],
            'reference' => ['required', 'string', 'max:60', 'unique:payments,reference'],
            'amount' => ['sometimes', 'numeric'],
        ]);

        $vendor = Vendor::where('code', $code)->firstOrFail();
        $this->authorizeVendorAccess($request, $vendor);

        $payment = Payment::create([
            'reference' => $data['reference'],
            'payable_type' => Vendor::class,
            'payable_id' => $vendor->id,
            'amount' => (float) config('scrapify.vendor_registration_fee', 5000),
            'method' => $data['method'],
            'status' => 'pending',
            'meta' => ['purpose' => 'vendor_registration'],
        ]);

        $vendor->update([
            'registration_step' => 4,
            'registration_payment_method' => $data['method'],
            'registration_payment_ref' => $data['reference'],
            'registration_payment_status' => 'pending',
        ]);

        return response()->json(['payment' => $payment, 'vendor' => new VendorResource($vendor)], 201);
    }

    public function approve(Request $request, string $code): VendorResource
    {
        $vendor = Vendor::where('code', $code)->firstOrFail();

        $vendor->update([
            'status' => 'approved',
            'rejection_reason' => null,
            'suspension_reason' => null,
            'registration_payment_status' => $vendor->registration_payment_ref ? 'verified' : $vendor->registration_payment_status,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        // An approved vendor needs a wallet to hold EMD against.
        if ($vendor->user) {
            app(WalletService::class)->forUser($vendor->user);
        }

        return new VendorResource($vendor->load(['materials', 'documents']));
    }

    public function reject(Request $request, string $code): VendorResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $vendor = Vendor::where('code', $code)->firstOrFail();

        $vendor->update([
            'status' => 'rejected',
            'rejection_reason' => $data['reason'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return new VendorResource($vendor);
    }

    public function suspend(Request $request, string $code): VendorResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $vendor = Vendor::where('code', $code)->firstOrFail();

        $vendor->update([
            'status' => 'suspended',
            'suspension_reason' => $data['reason'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return new VendorResource($vendor);
    }

    public function update(Request $request, string $code): VendorResource
    {
        $data = $request->validate([
            'company_name' => ['sometimes', 'string', 'max:180'],
            'location' => ['sometimes', 'nullable', 'string', 'max:180'],
            'contact_name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email'],
            'phone' => ['sometimes', 'string', 'max:20'],
            'gst_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'license_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'material_interest' => ['sometimes', 'array'],
        ]);

        $vendor = Vendor::where('code', $code)->firstOrFail();
        $vendor->update(collect($data)->except('material_interest')->all());

        if (array_key_exists('material_interest', $data)) {
            $vendor->materials()->sync($this->categoryIds($data['material_interest']));
        }

        return new VendorResource($vendor->fresh(['materials', 'documents']));
    }

    private function categoryIds(array $names): array
    {
        return Category::whereIn('name', $names)->orWhereIn('slug', $names)->pluck('id')->all();
    }

    private function authorizeVendorAccess(Request $request, Vendor $vendor): void
    {
        $user = $request->user();

        abort_unless(
            $user->isAdmin() || $user->vendor_id === $vendor->id,
            403,
            'You may only manage your own vendor profile.',
        );
    }
}
