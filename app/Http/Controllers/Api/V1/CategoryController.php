<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $roots = Category::whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => $roots->map(fn (Category $c) => self::mapCategory($c)),
            'flat' => Category::orderBy('sort_order')->get(['id', 'parent_id', 'name', 'slug']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'direction' => ['sometimes', 'string', 'in:forward,reverse,both'],
            'template_required' => ['sometimes', 'boolean'],
            'allow_manual_items' => ['sometimes', 'boolean'],
            'allow_excel' => ['sometimes', 'boolean'],
            'max_rows' => ['sometimes', 'integer', 'min:1', 'max:50000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['slug'] = Str::slug($data['name']);

        if (! isset($data['sort_order'])) {
            $data['sort_order'] = Category::max('sort_order') + 1;
        }

        $category = Category::create($data);

        AuditLogger::write('CATEGORY_CREATED', 'category', (string) $category->id, [
            'name' => $category->name,
            'parent_id' => $category->parent_id,
        ], $request->user());

        return response()->json(['data' => self::mapCategory($category->load('children'))], 201);
    }

    public function show(string $id): JsonResponse
    {
        $category = Category::with('children')->findOrFail($id);

        return response()->json(['data' => self::mapCategory($category)]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'direction' => ['sometimes', 'string', 'in:forward,reverse,both'],
            'template_required' => ['sometimes', 'boolean'],
            'allow_manual_items' => ['sometimes', 'boolean'],
            'allow_excel' => ['sometimes', 'boolean'],
            'max_rows' => ['sometimes', 'integer', 'min:1', 'max:50000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $category->update($data);

        AuditLogger::write('CATEGORY_UPDATED', 'category', (string) $category->id, [
            'name' => $category->name,
            'changes' => array_keys($data),
        ], $request->user());

        return response()->json(['data' => self::mapCategory($category->fresh('children'))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $auctionCount = \App\Models\Auction::where('category_id', $category->id)
            ->orWhere('subcategory_id', $category->id)
            ->count();
        abort_if($auctionCount > 0, 422, "Cannot delete category: {$auctionCount} auction(s) use it.");

        $templateCount = \App\Models\AuctionTemplate::where('category_id', $category->id)
            ->orWhere('subcategory_id', $category->id)
            ->count();
        abort_if($templateCount > 0, 422, "Cannot delete category: {$templateCount} template(s) use it.");

        $childCount = $category->children()->count();
        abort_if($childCount > 0, 422, "Cannot delete category with {$childCount} subcategories. Delete subcategories first.");

        AuditLogger::write('CATEGORY_DELETED', 'category', (string) $category->id, [
            'name' => $category->name,
        ], $request->user());

        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    private static function mapCategory(Category $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'slug' => $c->slug,
            'parent_id' => $c->parent_id,
            'direction' => $c->direction ?? 'both',
            'template_required' => (bool) ($c->template_required ?? false),
            'allow_manual_items' => (bool) ($c->allow_manual_items ?? true),
            'allow_excel' => (bool) ($c->allow_excel ?? true),
            'max_rows' => (int) ($c->max_rows ?? 1000),
            'is_active' => (bool) ($c->is_active ?? true),
            'sort_order' => (int) ($c->sort_order ?? 0),
            'children' => $c->relationLoaded('children')
                ? $c->children->map(fn (Category $child) => self::mapCategory($child))
                : [],
        ];
    }
}
