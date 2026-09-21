<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TermsCondition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TermsConditionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TermsCondition::with('category:id,name')
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        return response()->json(['data' => $query->paginate($request->integer('per_page', 50))]);
    }

    public function show(int $id): JsonResponse
    {
        $tnc = TermsCondition::with('category:id,name')->findOrFail($id);

        return response()->json(['data' => $tnc]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string|max:5000',
            'category_id' => 'nullable|integer|exists:categories,id',
            'applicable_to' => 'in:all,buyer,seller',
            'type' => 'in:general,registration,auction,payment,inspection,delivery,liability,dispute,compliance',
            'sort_order' => 'integer|min:0',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);

        $data['created_by'] = $request->user()?->id;
        $tnc = TermsCondition::create($data);

        return response()->json(['data' => $tnc->load('category:id,name')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tnc = TermsCondition::findOrFail($id);

        $data = $request->validate([
            'title' => 'string|max:255',
            'content' => 'string|max:5000',
            'category_id' => 'nullable|integer|exists:categories,id',
            'applicable_to' => 'in:all,buyer,seller',
            'type' => 'in:general,registration,auction,payment,inspection,delivery,liability,dispute,compliance',
            'sort_order' => 'integer|min:0',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ]);

        $data['updated_by'] = $request->user()?->id;
        $tnc->update($data);

        return response()->json(['data' => $tnc->fresh()->load('category:id,name')]);
    }

    public function destroy(int $id): JsonResponse
    {
        $tnc = TermsCondition::findOrFail($id);
        $tnc->delete();

        return response()->json(['message' => 'Terms & condition deleted.']);
    }

    public function forCategory(Request $request, int $categoryId): JsonResponse
    {
        $role = $request->query('role', 'buyer');

        $subcategoryId = $request->integer('subcategory_id') ?: null;

        $tncs = TermsCondition::active()
            ->forCategory($categoryId, $subcategoryId)
            ->forRole($role)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'title', 'content', 'type', 'applicable_to', 'category_id', 'sort_order']);

        return response()->json(['data' => $tncs]);
    }

    public function allActive(Request $request): JsonResponse
    {
        $role = $request->query('role', 'buyer');

        $query = TermsCondition::active()
            ->forRole($role)
            ->with('category:id,name')
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        $tncs = $query->get(['id', 'title', 'content', 'type', 'applicable_to', 'category_id', 'sort_order']);

        return response()->json(['data' => $tncs]);
    }
}
