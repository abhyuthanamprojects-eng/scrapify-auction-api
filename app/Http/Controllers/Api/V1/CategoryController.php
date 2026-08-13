<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * Two-level tree. The admin panel's six material types are the top level;
     * the mobile app's lot categories (IT Assets, PCBs, Cables, ...) sit
     * underneath them, so both taxonomies resolve to the same table.
     */
    public function index(): JsonResponse
    {
        $roots = Category::whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => $roots->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'children' => $c->children->map(fn (Category $child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'slug' => $child->slug,
                ]),
            ]),
            'flat' => Category::orderBy('sort_order')->get(['id', 'parent_id', 'name', 'slug']),
        ]);
    }
}
