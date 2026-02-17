<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    // ==========================================
    // LIST ALL STORES (Super Admin only)
    // ==========================================
    public function index(Request $request)
    {
        $query = Store::query();

        if ($request->search) {
            $query->where('name', 'ilike', "%{$request->search}%");
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $stores = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $stores,
        ]);
    }

    // ==========================================
    // GET MY STORES (User's assigned stores)
    // ==========================================
    public function myStores(Request $request)
    {
        $user = $request->user();

        if ($user->isSuperAdmin()) {
            $stores = Store::where('is_active', true)->orderBy('name')->get();
            return response()->json([
                'success' => true,
                'data' => $stores->map(fn(Store $s) => [
                    ...$s->toArray(),
                    'roles' => ['super_admin'],
                ]),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $user->getStoresWithRoles(),
        ]);
    }

    // ==========================================
    // GET STORE BY SLUG
    // ==========================================
    public function show(string $slug)
    {
        $store = Store::where('slug', $slug)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $store,
        ]);
    }

    // ==========================================
    // CREATE STORE (Super Admin only)
    // ==========================================
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:stores,slug',
            'type' => 'required|in:cafe,restaurant,market,minimarket',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'logo' => 'nullable|string',
        ]);

        $store = Store::create([
            'name' => $request->name,
            'slug' => $request->slug ?: Str::slug($request->name),
            'type' => $request->type,
            'address' => $request->address,
            'phone' => $request->phone,
            'logo' => $request->logo,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Store created successfully.',
            'data' => $store,
        ], 201);
    }

    // ==========================================
    // UPDATE STORE INFO (Store Admin/Manager)
    // ==========================================
    public function updateInfo(Request $request, string $slug)
    {
        $store = Store::where('slug', $slug)->firstOrFail();
        $user = $request->user();

        // Check if user has permission (Super Admin OR Role in this store)
        if (!$user->isSuperAdmin() && !$user->hasRoleInStore('admin', $store->id) && !$user->hasRoleInStore('manager', $store->id)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'logo' => 'nullable|string',
        ]);

        $store->update($request->only([
            'name',
            'address',
            'phone',
            'email',
            'logo',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Store info updated successfully.',
            'data' => $store->fresh(),
        ]);
    }

    // ==========================================
    // UPDATE STORE (Super Admin)
    // ==========================================
    public function update(Request $request, Store $store)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'sometimes|string|max:255|unique:stores,slug,' . $store->id,
            'type' => 'sometimes|in:cafe,restaurant,market,minimarket',
            'address' => 'nullable|string',
            'phone' => 'nullable|string|max:20',
            'logo' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $store->update($request->only([
            'name',
            'slug',
            'type',
            'address',
            'phone',
            'logo',
            'is_active'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Store updated successfully.',
            'data' => $store->fresh(),
        ]);
    }

    // ==========================================
    // DELETE STORE
    // ==========================================
    public function destroy(Store $store)
    {
        $store->delete();

        return response()->json([
            'success' => true,
            'message' => 'Store deleted successfully.',
        ]);
    }
}
