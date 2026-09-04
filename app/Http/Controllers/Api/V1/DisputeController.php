<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\DisputeTimeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = Dispute::with(['vendor', 'auction', 'order', 'evidence', 'timeline']);

        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        if ($severity = $request->query('severity')) {
            $q->where('severity', $severity);
        }

        if ($user && $user->vendor) {
            $q->where('vendor_id', $user->vendor->id);
        }

        $disputes = $q->orderByDesc('created_at')->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $disputes->items(),
            'meta' => [
                'current_page' => $disputes->currentPage(),
                'last_page' => $disputes->lastPage(),
                'total' => $disputes->total(),
            ],
        ]);
    }

    public function show(string $code): JsonResponse
    {
        $dispute = Dispute::where('code', $code)
            ->with(['vendor', 'auction', 'order', 'evidence', 'timeline', 'investigator'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $dispute,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $vendor = $user->vendor;

        if (! $vendor) {
            return response()->json(['success' => false, 'message' => 'Vendor profile required to raise a dispute.'], 403);
        }

        // Normalize aliases from mobile and web apps
        $input = $request->all();
        if (! isset($input['title']) && isset($input['subject'])) {
            $input['title'] = $input['subject'];
        }
        if (! isset($input['claim_amount']) && isset($input['claimed_amount'])) {
            $input['claim_amount'] = $input['claimed_amount'];
        }
        if (! isset($input['severity'])) {
            $input['severity'] = 'Medium';
        }

        $request->merge($input);

        $validated = $request->validate([
            'auction_id' => 'nullable',
            'order_id' => 'nullable',
            'category' => 'required|string',
            'severity' => 'sometimes|in:Low,Medium,High,Critical',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'claim_amount' => 'nullable|numeric|min:0',
            'evidence' => 'nullable|array',
            'evidence.*.title' => 'required|string',
            'evidence.*.file_url' => 'required|string',
        ]);

        $orderId = null;
        if (! empty($validated['order_id'])) {
            $orderId = \App\Models\Order::where('id', $validated['order_id'])
                ->orWhere('code', $validated['order_id'])
                ->value('id');
        }

        $auctionId = null;
        if (! empty($validated['auction_id'])) {
            $auctionId = \App\Models\Auction::where('id', $validated['auction_id'])
                ->orWhere('code', $validated['auction_id'])
                ->value('id');
        }

        $code = 'DISP-'.now()->year.'-'.str_pad((string) (Dispute::count() + 1), 4, '0', STR_PAD_LEFT);

        $dispute = Dispute::create([
            'code' => $code,
            'auction_id' => $auctionId,
            'order_id' => $orderId,
            'vendor_id' => $vendor->id,
            'raised_by_user_id' => $user->id,
            'category' => $validated['category'],
            'severity' => $validated['severity'] ?? 'Medium',
            'title' => $validated['title'],
            'description' => $validated['description'],
            'claim_amount' => $validated['claim_amount'] ?? 0,
            'status' => 'new',
        ]);

        if (! empty($validated['evidence'])) {
            foreach ($validated['evidence'] as $ev) {
                DisputeEvidence::create([
                    'dispute_id' => $dispute->id,
                    'title' => $ev['title'],
                    'file_url' => $ev['file_url'],
                    'uploaded_by_user_id' => $user->id,
                ]);
            }
        }

        DisputeTimeline::create([
            'dispute_id' => $dispute->id,
            'author_name' => $user->name.' ('.$vendor->company_name.')',
            'author_role' => 'Claimant',
            'message' => 'Commercial dispute case logged for formal arbitration review.',
            'user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dispute case registered successfully.',
            'data' => $dispute->fresh(['evidence', 'timeline']),
        ], 201);
    }

    public function addTimelineMessage(Request $request, string $code): JsonResponse
    {
        $dispute = Dispute::where('code', $code)->firstOrFail();
        $user = $request->user();

        $validated = $request->validate([
            'message' => 'required|string',
        ]);

        $msg = DisputeTimeline::create([
            'dispute_id' => $dispute->id,
            'author_name' => $user->name,
            'author_role' => $user->vendor_id ? 'Vendor' : 'Operations Arbitrator',
            'message' => $validated['message'],
            'user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Evidence and communication logged.',
            'data' => $msg,
        ]);
    }

    public function uploadEvidence(Request $request, string $code): JsonResponse
    {
        $dispute = Dispute::where('code', $code)->firstOrFail();
        $user = $request->user();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'file' => 'required|file|max:10240',
        ]);

        $path = $request->file('file')->store('dispute-evidence', 'public');
        $originalName = $request->file('file')->getClientOriginalName();
        $mimeType = $request->file('file')->getMimeType();

        $evidence = DisputeEvidence::create([
            'dispute_id' => $dispute->id,
            'title' => $validated['title'],
            'file_url' => $path,
            'file_type' => $mimeType,
            'uploaded_by_user_id' => $user->id,
        ]);

        DisputeTimeline::create([
            'dispute_id' => $dispute->id,
            'author_name' => $user->name,
            'author_role' => $user->vendor_id ? 'Vendor' : 'Operations',
            'message' => "Evidence uploaded: {$validated['title']} ({$originalName})",
            'user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Evidence uploaded successfully.',
            'data' => $evidence,
        ], 201);
    }

    public function resolve(Request $request, string $code): JsonResponse
    {
        $dispute = Dispute::where('code', $code)->firstOrFail();
        $user = $request->user();

        $validated = $request->validate([
            'resolution_summary' => 'required|string',
            'status' => 'required|in:resolved,closed,appealed',
        ]);

        $dispute->update([
            'status' => $validated['status'],
            'resolution_summary' => $validated['resolution_summary'],
            'resolved_at' => now(),
        ]);

        DisputeTimeline::create([
            'dispute_id' => $dispute->id,
            'author_name' => $user->name.' (Arbitrator)',
            'author_role' => 'Operations Arbitrator',
            'message' => 'Official Case Resolution: '.$validated['resolution_summary'],
            'user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dispute resolution recorded.',
            'data' => $dispute,
        ]);
    }
}
