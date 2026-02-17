<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ProductController extends Controller
{
    // ==========================================
    // LIST PRODUCTS
    // ==========================================
    public function index(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $query = Product::where('store_id', $store->id)->with('category:id,name');

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'ilike', "%{$request->search}%")
                    ->orWhere('barcode', 'ilike', "%{$request->search}%");
            });
        }

        if ($request->category_id) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        if ($request->boolean('active_only')) {
            $query->active()->inStock();
        }

        if ($request->boolean('low_stock')) {
            $query->where('stock', '<=', 10);
        }

        // Sorting
        $sortField = $request->input('sort_by', 'name');
        $sortOrder = $request->input('sort_order', 'asc');

        $allowedSorts = ['name', 'price', 'stock', 'created_at', 'updated_at'];

        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('name', 'asc');
        }

        $products = $query->get();

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    // ==========================================
    // SHOW PRODUCT
    // ==========================================
    public function show(string $storeSlug, Product $product)
    {
        return response()->json([
            'success' => true,
            'data' => $product->load('category'),
        ]);
    }

    // ==========================================
    // CREATE PRODUCT
    // ==========================================
    public function store(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'barcode' => 'nullable|string|max:100|unique:products,barcode',
            'image' => 'nullable|string',
        ]);

        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $request->category_id,
            'name' => $request->name,
            'description' => $request->description,
            'price' => $request->price,
            'stock' => $request->stock,
            'barcode' => $request->barcode ?? $this->generateUniqueBarcode(),
            'image' => $request->image,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully.',
            'data' => $product->load('category'),
        ], 201);
    }

    // ==========================================
    // UPDATE PRODUCT
    // ==========================================
    public function update(Request $request, string $storeSlug, Product $product)
    {
        $request->validate([
            'category_id' => 'nullable|exists:categories,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|numeric|min:0',
            'stock' => 'sometimes|integer|min:0',
            'barcode' => 'nullable|string|max:100|unique:products,barcode,' . $product->id,
            'image' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $product->update($request->only([
            'category_id',
            'name',
            'description',
            'price',
            'stock',
            'barcode',
            'image',
            'is_active'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully.',
            'data' => $product->fresh()->load('category'),
        ]);
    }

    // ==========================================
    // DELETE PRODUCT
    // ==========================================
    public function destroy(string $storeSlug, Product $product)
    {
        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
        ]);
    }

    // ==========================================
    // UPDATE STOCK
    // ==========================================
    public function updateStock(Request $request, string $storeSlug, Product $product)
    {
        $request->validate([
            'adjustment' => 'required|integer',
            'reason' => 'nullable|string',
        ]);

        if ($request->adjustment > 0) {
            $product->increaseStock($request->adjustment);
        } else {
            $product->decreaseStock(abs($request->adjustment));
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock updated successfully.',
            'data' => $product->fresh(),
        ]);
    }
    // ==========================================
    // FIND BY BARCODE
    // ==========================================
    public function findByBarcode(string $storeSlug, string $barcode)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $product = Product::where('store_id', $store->id)
            ->where('barcode', $barcode)
            ->with('category:id,name')
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    // ==========================================
    // BULK IMPORT PRODUCTS
    // ==========================================
    public function bulkImport(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $request->validate([
            'products' => 'required|array|min:1',
            'products.*.name' => 'required|string|max:255',
            'products.*.price' => 'required|numeric|min:0',
            'products.*.stock' => 'required|numeric|min:0',
            'products.*.category' => 'nullable|string|max:255',
            'products.*.description' => 'nullable|string',
            'products.*.barcode' => 'nullable|string|max:100',
            'products.*.image' => 'nullable|string',
        ]);

        $created = 0;
        $skipped = [];
        $errors = [];

        // Pre-fetch existing product names for this store (case-insensitive)
        $existingNames = Product::where('store_id', $store->id)
            ->pluck('name')
            ->map(fn($n) => strtolower(trim($n)))
            ->toArray();

        // Pre-fetch categories for this store
        $categories = \App\Models\Category::where('store_id', $store->id)
            ->get()
            ->keyBy(fn($c) => strtolower(trim($c->name)));

        foreach ($request->products as $index => $row) {
            $name = trim($row['name']);

            // Skip if name already exists
            if (in_array(strtolower($name), $existingNames)) {
                $skipped[] = $name;
                continue;
            }

            try {
                // Resolve category by name
                $categoryId = null;
                if (!empty($row['category'])) {
                    $catKey = strtolower(trim($row['category']));
                    if ($categories->has($catKey)) {
                        $categoryId = $categories->get($catKey)->id;
                    } else {
                        // Auto-create category
                        $newCat = \App\Models\Category::create([
                            'store_id' => $store->id,
                            'name' => trim($row['category']),
                        ]);
                        $categories->put($catKey, $newCat);
                        $categoryId = $newCat->id;
                    }
                }

                Product::create([
                    'store_id' => $store->id,
                    'category_id' => $categoryId,
                    'name' => $name,
                    'description' => $row['description'] ?? null,
                    'price' => floatval($row['price']),
                    'stock' => intval($row['stock']),
                    'barcode' => !empty($row['barcode']) ? $row['barcode'] : $this->generateUniqueBarcode(),
                    'image' => $row['image'] ?? null,
                ]);

                $created++;
                $existingNames[] = strtolower($name); // Prevent duplicates within the batch
            } catch (\Exception $e) {
                $errors[] = "Row " . ($index + 1) . " ({$name}): " . $e->getMessage();
            }
        }

        return response()->json([
            'success' => true,
            'message' => "{$created} products imported successfully.",
            'data' => [
                'created' => $created,
                'skipped' => $skipped,
                'errors' => $errors,
            ],
        ]);
    }

    // ==========================================
    // HELPERS
    // ==========================================
    private function generateUniqueBarcode()
    {
        do {
            $barcode = str_pad(mt_rand(0, 999999999999), 12, '0', STR_PAD_LEFT);
        } while (Product::where('barcode', $barcode)->exists());

        return $barcode;
    }
}
