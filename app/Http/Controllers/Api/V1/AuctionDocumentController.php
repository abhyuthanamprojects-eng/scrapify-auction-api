<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionDocument;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AuctionDocumentController extends Controller
{
    public function index(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $this->authorizeAccess($request, $auction);

        return response()->json([
            'documents' => $auction->documents()
                ->where('submission_version', $auction->submission_version ?: 1)
                ->get(),
        ]);
    }

    public function upload(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $this->authorizeOwner($request, $auction);

        abort_unless(
            in_array($auction->status, ['draft', 'sent_back'], true),
            422,
            'Documents can only be uploaded for draft or sent-back auctions.',
        );

        $data = $request->validate([
            'doc_type' => ['required', Rule::in(AuctionDocument::DOC_TYPES)],
            'file' => ['required', 'file', 'max:20480'],
        ]);

        $file = $request->file('file');
        $docType = $data['doc_type'];

        $this->validatePdfFile($file);

        $version = max($auction->submission_version, 1);
        $hash = hash_file('sha256', $file->getRealPath());
        $path = $file->store("auction-documents/{$auction->code}", 'local');

        $requiredDocs = AuctionDocument::requiredDocsForDirection($auction->direction);

        $doc = AuctionDocument::updateOrCreate(
            [
                'auction_id' => $auction->id,
                'doc_type' => $docType,
                'submission_version' => $version,
            ],
            [
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'disk' => 'local',
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'file_hash' => $hash,
                'status' => 'pending_review',
                'required' => $requiredDocs[$docType] ?? false,
                'uploaded_by' => $request->user()->id,
                'uploaded_at' => now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_remarks' => null,
            ],
        );

        AuditLogger::write(
            "Uploaded {$docType} document for auction {$auction->code}",
            'AuctionDocument',
            (string) $doc->id,
        );

        return response()->json(['document' => $doc], 201);
    }

    public function download(Request $request, string $code, int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $this->authorizeAccess($request, $auction);

        $doc = $auction->documents()->findOrFail($id);

        abort_unless(
            Storage::disk($doc->disk)->exists($doc->file_path),
            404,
            'Document file not found.',
        );

        return Storage::disk($doc->disk)->download($doc->file_path, $doc->file_name);
    }

    public function adminIndex(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();

        $docs = $auction->documents()
            ->with(['reviewer:id,name', 'uploader:id,name'])
            ->orderBy('submission_version')
            ->orderBy('doc_type')
            ->get();

        $requiredDocs = AuctionDocument::requiredDocsForDirection($auction->direction);

        $materialListRequired = $auction->direction === 'forward';
        $latestUpload = $auction->templateUploads()->first();
        $materialListStatus = 'not_provided';
        if ($latestUpload) {
            $materialListStatus = $latestUpload->confirmed_at ? 'pending_review' : 'uploaded';
        }

        return response()->json([
            'documents' => $docs,
            'requirements' => $requiredDocs,
            'material_list' => [
                'required' => $materialListRequired,
                'status' => $materialListStatus,
                'upload' => $latestUpload,
            ],
        ]);
    }

    public function review(Request $request, string $code, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['verified', 'changes_required', 'rejected'])],
            'remarks' => ['required_if:status,changes_required', 'required_if:status,rejected', 'nullable', 'string', 'max:1000'],
        ]);

        $auction = Auction::where('code', $code)->firstOrFail();
        $doc = $auction->documents()->findOrFail($id);

        $doc->update([
            'status' => $data['status'],
            'review_remarks' => $data['remarks'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        AuditLogger::write(
            "Reviewed {$doc->doc_type} for auction {$auction->code}: {$data['status']}",
            'AuctionDocument',
            (string) $doc->id,
        );

        return response()->json(['document' => $doc->fresh(['reviewer:id,name'])]);
    }

    private function validatePdfFile(\Illuminate\Http\UploadedFile $file): void
    {
        $mime = $file->getMimeType();
        abort_unless($mime === 'application/pdf', 422, 'Only PDF files are accepted for this document type.');

        $ext = strtolower($file->getClientOriginalExtension());
        abort_unless($ext === 'pdf', 422, 'File extension must be .pdf.');

        $handle = fopen($file->getRealPath(), 'rb');
        $header = fread($handle, 5);
        fclose($handle);
        abort_unless(str_starts_with($header, '%PDF'), 422, 'File does not appear to be a valid PDF (invalid file signature).');
    }

    private function authorizeOwner(Request $request, Auction $auction): void
    {
        $user = $request->user();
        $isStaff = $user->hasPermission('auctions.approve') || $user->hasPermission('auctions.create_any');

        if (! $isStaff) {
            abort_unless($auction->submitted_by === $user->id, 403, 'You may only manage documents for your own auctions.');
        }
    }

    private function authorizeAccess(Request $request, Auction $auction): void
    {
        $user = $request->user();
        $isStaff = $user->hasPermission('auctions.approve') || $user->hasPermission('auctions.create_any');

        if (! $isStaff) {
            abort_unless($auction->submitted_by === $user->id, 403, 'You may only access your own auction documents.');
        }
    }
}
