<?php

namespace App\Http\Controllers\Api;

use App\Models\Category;
use App\Models\Store;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class CategoryController extends Controller
{
    // ==========================================
    // LIST CATEGORIES
    // ==========================================
    public function index(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $query = Category::where('store_id', $store->id);

        if ($request->has('active')) {
            // Postgres strict boolean check
            $isActive = $request->boolean('active');
            if ($isActive) {
                $query->whereRaw('is_active = true');
            } else {
                $query->whereRaw('is_active = false');
            }
        }

        $categories = $query->ordered()->withCount('products')->get();

        return response()->json([
            'success' => true,
            'data' => $categories,
        ]);
    }

    // ==========================================
    // SHOW CATEGORY
    // ==========================================
    public function show(string $storeSlug, Category $category)
    {
        return response()->json([
            'success' => true,
            'data' => $category->load('products'),
        ]);
    }

    // ==========================================
    // CREATE CATEGORY
    // ==========================================
    public function store(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'sort_order' => 'nullable|integer',
        ]);

        $category = Category::create([
            'store_id' => $store->id,
            'name' => $request->name,
            'description' => $request->description,
            'image' => $request->image,
            'sort_order' => $request->input('sort_order', 0),
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Category created successfully.',
            'data' => $category,
        ], 201);
    }

    // ==========================================
    // UPDATE CATEGORY
    // ==========================================
    public function update(Request $request, string $storeSlug, Category $category)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active' => 'sometimes|boolean',
        ]);

        $category->update($request->only([
            'name',
            'description',
            'image',
            'sort_order',
            'is_active'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Category updated successfully.',
            'data' => $category->fresh(),
        ]);
    }

    // ==========================================
    // DELETE CATEGORY
    // ==========================================
    public function destroy(string $storeSlug, Category $category)
    {
        $category->delete();

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully.',
        ]);
    }
}
