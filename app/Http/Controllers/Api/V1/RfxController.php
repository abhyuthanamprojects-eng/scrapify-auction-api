<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\RfxEvaluation;
use App\Models\RfxPackage;
use App\Models\RfxQuestion;
use App\Models\RfxResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RfxController extends Controller
{
    public function index(string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $packages = RfxPackage::where('auction_id', $auction->id)
            ->with(['questions', 'responses.vendor'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $packages,
        ]);
    }

    public function store(Request $request, string $code): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|in:rfi,rfq,rfp',
            'stage' => 'nullable|string',
            'submission_deadline' => 'nullable|date',
            'is_mandatory' => 'boolean',
            'min_passing_score' => 'nullable|numeric|min:0|max:100',
            'questions' => 'nullable|array',
            'questions.*.section' => 'nullable|string',
            'questions.*.question_text' => 'required|string',
            'questions.*.type' => 'required|string',
            'questions.*.options' => 'nullable|array',
            'questions.*.weight' => 'nullable|numeric',
            'questions.*.is_required' => 'boolean',
        ]);

        $package = RfxPackage::create([
            'auction_id' => $auction->id,
            'title' => $validated['title'],
            'type' => $validated['type'],
            'stage' => $validated['stage'] ?? 'technical',
            'submission_deadline' => $validated['submission_deadline'] ?? null,
            'is_mandatory' => $validated['is_mandatory'] ?? true,
            'min_passing_score' => $validated['min_passing_score'] ?? 70.00,
            'status' => 'open',
        ]);

        if (! empty($validated['questions'])) {
            foreach ($validated['questions'] as $idx => $q) {
                RfxQuestion::create([
                    'rfx_package_id' => $package->id,
                    'section' => $q['section'] ?? 'General',
                    'question_text' => $q['question_text'],
                    'type' => $q['type'],
                    'options' => $q['options'] ?? null,
                    'weight' => $q['weight'] ?? 10.00,
                    'is_required' => $q['is_required'] ?? true,
                    'sort_order' => $idx + 1,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'RFx package configured successfully.',
            'data' => $package->fresh('questions'),
        ], 201);
    }

    public function submitResponse(Request $request, string $code, int $packageId): JsonResponse
    {
        $auction = Auction::where('code', $code)->firstOrFail();
        $package = RfxPackage::where('auction_id', $auction->id)->findOrFail($packageId);
        $user = $request->user();
        $vendor = $user->vendor;

        if (! $vendor) {
            return response()->json(['success' => false, 'message' => 'User does not belong to a vendor profile.'], 403);
        }

        $validated = $request->validate([
            'answers' => 'required|array',
        ]);

        $response = RfxResponse::updateOrCreate(
            ['rfx_package_id' => $package->id, 'vendor_id' => $vendor->id],
            [
                'user_id' => $user->id,
                'answers' => $validated['answers'],
                'status' => 'submitted',
                'submitted_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'RFx response submitted successfully.',
            'data' => $response,
        ]);
    }

    public function evaluateResponse(Request $request, string $code, int $responseId): JsonResponse
    {
        $response = RfxResponse::findOrFail($responseId);
        $user = $request->user();

        $validated = $request->validate([
            'technical_score' => 'required|numeric|min:0|max:100',
            'commercial_score' => 'nullable|numeric|min:0|max:100',
            'passed' => 'required|boolean',
            'comments' => 'nullable|string',
        ]);

        $eval = RfxEvaluation::updateOrCreate(
            ['rfx_response_id' => $response->id, 'evaluator_id' => $user->id],
            [
                'technical_score' => $validated['technical_score'],
                'commercial_score' => $validated['commercial_score'] ?? 0,
                'total_score' => $validated['technical_score'] + ($validated['commercial_score'] ?? 0),
                'passed' => $validated['passed'],
                'comments' => $validated['comments'] ?? null,
            ]
        );

        $response->update([
            'status' => $validated['passed'] ? 'qualified' : 'disqualified',
            'score' => $eval->total_score,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Evaluation recorded successfully.',
            'data' => $eval,
        ]);
    }
}
