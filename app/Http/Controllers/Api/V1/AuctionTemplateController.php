<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionTemplate;
use App\Models\AuctionTemplateUpload;
use App\Models\Category;
use App\Services\AuditLogger;
use App\Services\TemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AuctionTemplateController extends Controller
{
    public function __construct(private TemplateService $templateService) {}

    public function index(Request $request): JsonResponse
    {
        $q = AuctionTemplate::with(['category', 'subcategory'])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($categoryId = $request->query('category_id')) {
            $q->where('category_id', $categoryId);
        }
        if ($direction = $request->query('direction')) {
            $q->forDirection($direction);
        }

        return response()->json(['data' => $q->paginate(25)]);
    }

    public function show(string $id): JsonResponse
    {
        $template = AuctionTemplate::with(['category', 'subcategory'])->findOrFail($id);

        return response()->json(['data' => $template]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:180'],
            'category_id' => ['required', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'exists:categories,id'],
            'direction' => ['sometimes', Rule::in(['forward', 'reverse', 'both'])],
            'version' => ['sometimes', 'string', 'max:20'],
            'schema_definition' => ['required', 'array'],
            'schema_definition.columns' => ['required', 'array', 'min:1'],
            'schema_definition.columns.*.key' => ['required', 'string'],
            'schema_definition.columns.*.label' => ['required', 'string'],
            'schema_definition.columns.*.type' => ['sometimes', 'string'],
            'schema_definition.columns.*.required' => ['sometimes', 'boolean'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'max_rows' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'max_file_size' => ['sometimes', 'integer', 'min:1024'],
            'template_required' => ['sometimes', 'boolean'],
            'allow_manual_items' => ['sometimes', 'boolean'],
        ]);

        $data['status'] = 'draft';
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;

        $template = AuctionTemplate::create($data);

        AuditLogger::write('TEMPLATE_CREATED', 'auction_template', $template->code, [
            'template_code' => $template->template_code,
            'version' => $template->version,
        ], $request->user());

        return response()->json(['data' => $template->load(['category', 'subcategory'])], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $template = AuctionTemplate::findOrFail($id);

        if ($template->isUsedByAuction() && in_array($template->status, ['active', 'deprecated'])) {
            abort(422, 'This template version is used by auctions and cannot be modified. Create a new version instead.');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'direction' => ['sometimes', Rule::in(['forward', 'reverse', 'both'])],
            'schema_definition' => ['sometimes', 'array'],
            'schema_definition.columns' => ['sometimes', 'array', 'min:1'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'max_rows' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'max_file_size' => ['sometimes', 'integer', 'min:1024'],
            'template_required' => ['sometimes', 'boolean'],
            'allow_manual_items' => ['sometimes', 'boolean'],
        ]);

        $data['updated_by'] = $request->user()->id;
        $template->update($data);

        return response()->json(['data' => $template->fresh(['category', 'subcategory'])]);
    }

    public function activate(Request $request, string $id): JsonResponse
    {
        $template = AuctionTemplate::findOrFail($id);
        abort_if($template->status === 'active', 422, 'Template is already active.');

        AuctionTemplate::where('template_code', $template->template_code)
            ->where('id', '!=', $template->id)
            ->where('status', 'active')
            ->update(['status' => 'deprecated']);

        $template->update(['status' => 'active', 'effective_from' => now()]);

        AuditLogger::write('TEMPLATE_ACTIVATED', 'auction_template', $template->code, [
            'template_code' => $template->template_code,
            'version' => $template->version,
        ], $request->user());

        return response()->json(['data' => $template->fresh(['category', 'subcategory'])]);
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $template = AuctionTemplate::findOrFail($id);
        abort_if($template->status !== 'active', 422, 'Only active templates can be deactivated.');

        $template->update(['status' => 'deprecated', 'effective_to' => now()]);

        return response()->json(['data' => $template->fresh(['category', 'subcategory'])]);
    }

    public function newVersion(Request $request, string $id): JsonResponse
    {
        $source = AuctionTemplate::findOrFail($id);

        $data = $request->validate([
            'version' => ['required', 'string', 'max:20'],
            'schema_definition' => ['sometimes', 'array'],
            'instructions' => ['nullable', 'string', 'max:5000'],
        ]);

        $existing = AuctionTemplate::where('template_code', $source->template_code)
            ->where('version', $data['version'])
            ->exists();
        abort_if($existing, 422, 'Version '.$data['version'].' already exists for this template.');

        $newTemplate = AuctionTemplate::create([
            'template_code' => $source->template_code,
            'name' => $source->name,
            'category_id' => $source->category_id,
            'subcategory_id' => $source->subcategory_id,
            'direction' => $source->direction,
            'version' => $data['version'],
            'status' => 'draft',
            'schema_definition' => $data['schema_definition'] ?? $source->schema_definition,
            'instructions' => $data['instructions'] ?? $source->instructions,
            'max_rows' => $source->max_rows,
            'max_file_size' => $source->max_file_size,
            'template_required' => $source->template_required,
            'allow_manual_items' => $source->allow_manual_items,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        AuditLogger::write('TEMPLATE_VERSION_CREATED', 'auction_template', $newTemplate->code, [
            'template_code' => $newTemplate->template_code,
            'source_version' => $source->version,
            'new_version' => $data['version'],
        ], $request->user());

        return response()->json(['data' => $newTemplate->load(['category', 'subcategory'])], 201);
    }

    public function download(string $id): BinaryFileResponse
    {
        $template = AuctionTemplate::findOrFail($id);
        $path = $this->templateService->generateExcel($template);

        return response()->download($path, $template->template_code.'_v'.$template->version.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function forCategory(Request $request, string $categoryId): JsonResponse
    {
        $category = Category::findOrFail($categoryId);
        $direction = $request->query('direction', 'forward');

        $template = AuctionTemplate::active()
            ->forCategory($category->id, $request->query('subcategory_id'))
            ->forDirection($direction)
            ->first();

        if (! $template) {
            return response()->json(['data' => null, 'message' => 'No active template found for this category.']);
        }

        return response()->json(['data' => $template->load(['category', 'subcategory'])]);
    }

    public function uploadTemplate(Request $request, string $auctionCode): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'template_id' => ['required', 'exists:auction_templates,id'],
        ]);

        $auction = Auction::where('code', $auctionCode)->firstOrFail();
        $this->authorizeOwnerOrStaff($request, $auction);

        abort_if(
            in_array($auction->status, ['closed', 'cancelled', 'live']),
            422,
            'Cannot upload template for this auction.',
        );

        $template = AuctionTemplate::findOrFail($request->input('template_id'));
        abort_unless($template->isActive(), 422, 'This template version is not active.');

        if ($auction->category_id && $template->category_id !== $auction->category_id) {
            abort(422, 'Template category does not match auction category.');
        }

        if ($auction->direction && $template->direction !== 'both' && $template->direction !== $auction->direction) {
            abort(422, 'Template direction does not match auction direction.');
        }

        $result = $this->templateService->validateAndParseUpload(
            $request->file('file'),
            $template,
            $auction,
        );

        $upload = $this->templateService->storeUpload(
            $request->file('file'),
            $auction,
            $template,
            $result,
            $request->user()->id,
        );

        AuditLogger::write('TEMPLATE_UPLOADED', 'auction', $auction->code, [
            'upload_id' => $upload->id,
            'template_code' => $template->template_code,
            'valid' => $result['valid'],
            'file_hash' => $result['file_hash'] ?? null,
        ], $request->user());

        return response()->json([
            'data' => [
                'upload' => $upload,
                'valid' => $result['valid'],
                'errors' => $result['errors'] ?? [],
                'row_count' => $result['row_count'] ?? 0,
                'total_quantity' => $result['total_quantity'] ?? 0,
                'total_reference_value' => $result['total_reference_value'] ?? 0,
                'rows' => $result['valid'] ? $result['rows'] : [],
            ],
        ], $result['valid'] ? 200 : 422);
    }

    public function confirmImport(Request $request, string $auctionCode, string $uploadId): JsonResponse
    {
        $auction = Auction::where('code', $auctionCode)->firstOrFail();
        $this->authorizeOwnerOrStaff($request, $auction);

        abort_if(
            in_array($auction->status, ['closed', 'cancelled', 'live']),
            422,
            'Cannot import items for this auction.',
        );

        $upload = AuctionTemplateUpload::where('id', $uploadId)
            ->where('auction_id', $auction->id)
            ->firstOrFail();

        abort_unless($upload->status === 'valid', 422, 'Only validated uploads can be confirmed.');
        abort_if($upload->confirmed_at, 422, 'This upload has already been confirmed.');

        $this->templateService->confirmImport($upload);

        AuditLogger::write('TEMPLATE_IMPORT_CONFIRMED', 'auction', $auction->code, [
            'upload_id' => $upload->id,
            'submission_version' => $upload->submission_version,
            'row_count' => $upload->row_count,
        ], $request->user());

        return response()->json([
            'data' => [
                'upload' => $upload->fresh(),
                'auction' => $auction->fresh(['lots', 'items', 'template']),
            ],
            'message' => $upload->row_count.' items imported successfully.',
        ]);
    }

    public function uploadValidationResult(Request $request, string $auctionCode, string $uploadId): JsonResponse
    {
        $auction = Auction::where('code', $auctionCode)->firstOrFail();
        $this->authorizeOwnerOrStaff($request, $auction);

        $upload = AuctionTemplateUpload::where('id', $uploadId)
            ->where('auction_id', $auction->id)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'upload' => $upload->load('template'),
                'items' => $upload->status === 'parsed'
                    ? $auction->items()->where('upload_id', $upload->id)->paginate(50)
                    : null,
            ],
        ]);
    }

    public function adminDownloadSource(Request $request, string $auctionCode, string $uploadId): BinaryFileResponse
    {
        $auction = Auction::where('code', $auctionCode)->firstOrFail();
        $upload = AuctionTemplateUpload::where('id', $uploadId)
            ->where('auction_id', $auction->id)
            ->firstOrFail();

        $path = \Illuminate\Support\Facades\Storage::disk($upload->disk)->path($upload->stored_path);
        abort_unless(file_exists($path), 404, 'Source file not found.');

        return response()->download($path, $upload->original_filename, [
            'Content-Type' => $upload->mime_type,
        ]);
    }

    public function adminParsedItems(Request $request, string $auctionCode): JsonResponse
    {
        $auction = Auction::where('code', $auctionCode)->firstOrFail();

        return response()->json([
            'data' => $auction->items()->with('lot')->paginate(
                (int) $request->query('per_page', 50),
            ),
        ]);
    }

    public function adminTemplateReview(Request $request, string $auctionCode): JsonResponse
    {
        $auction = Auction::where('code', $auctionCode)
            ->with(['template', 'templateUploads.template'])
            ->firstOrFail();

        $latestUpload = $auction->templateUploads->first();

        return response()->json([
            'data' => [
                'auction_code' => $auction->code,
                'template' => $auction->template,
                'template_version' => $auction->template_version,
                'submission_version' => $auction->submission_version,
                'uploads' => $auction->templateUploads,
                'latest_upload' => $latestUpload ? [
                    'id' => $latestUpload->id,
                    'original_filename' => $latestUpload->original_filename,
                    'file_hash' => $latestUpload->file_hash,
                    'file_size' => $latestUpload->file_size,
                    'row_count' => $latestUpload->row_count,
                    'total_quantity' => $latestUpload->total_quantity,
                    'total_reference_value' => $latestUpload->total_reference_value,
                    'status' => $latestUpload->status,
                    'submission_version' => $latestUpload->submission_version,
                    'created_at' => $latestUpload->created_at,
                ] : null,
                'item_count' => $auction->items()->count(),
                'lot_count' => $auction->lots()->count(),
            ],
        ]);
    }

    private function authorizeOwnerOrStaff(Request $request, Auction $auction): void
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->hasPermission('auctions.approve') || (int) $auction->submitted_by === (int) $user->id),
            403,
            'You may only manage your own auction templates.',
        );
    }
}
