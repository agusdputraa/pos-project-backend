<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Role;
use App\Models\UserStoreRole;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class UserController extends Controller
{
    // ==========================================
    // LIST USERS (Super Admin only)
    // ==========================================
    public function index(Request $request)
    {
        $query = User::with('storeRoles.store', 'storeRoles.role');

        if ($request->search) {
            $searchTerm = '%' . strtolower($request->search) . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->whereRaw('LOWER(name) LIKE ?', [$searchTerm])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$searchTerm]);
            });
        }

        if ($request->store_id) {
            $query->whereHas('storeRoles', function ($q) use ($request) {
                $q->where('store_id', $request->store_id);
            });
        }

        $users = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $users->map(fn(User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'is_super_admin' => $u->is_super_admin,
                'stores' => $u->getStoresWithRoles(),
            ]),
        ]);
    }

    // ==========================================
    // SHOW USER
    // ==========================================
    public function show(User $user)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_super_admin' => $user->is_super_admin,
                'stores' => $user->getStoresWithDetailedRoles(),
            ],
        ]);
    }

    // ==========================================
    // CREATE USER
    // ==========================================
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8',
            'is_super_admin' => 'boolean',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'is_super_admin' => $request->boolean('is_super_admin'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_super_admin' => $user->is_super_admin,
            ],
        ], 201);
    }

    // ==========================================
    // UPDATE USER
    // ==========================================
    public function update(Request $request, User $user)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'password' => 'sometimes|min:8',
            'is_super_admin' => 'boolean',
        ]);

        $data = $request->only(['name', 'email', 'is_super_admin']);
        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully.',
            'data' => $user->fresh(),
        ]);
    }

    // ==========================================
    // DELETE USER
    // ==========================================
    public function destroy(User $user)
    {
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully.',
        ]);
    }

    // ==========================================
    // ASSIGN ROLE TO USER IN STORE
    // ==========================================
    public function assignRole(Request $request, User $user)
    {
        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'role_id' => 'required|exists:roles,id',
        ]);

        UserStoreRole::firstOrCreate([
            'user_id' => $user->id,
            'store_id' => $request->store_id,
            'role_id' => $request->role_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully.',
            'data' => $user->getStoresWithRoles(),
        ]);
    }

    // ==========================================
    // REMOVE ROLE FROM USER IN STORE
    // ==========================================
    public function removeRole(Request $request, User $user)
    {
        $request->validate([
            'store_id' => 'required|exists:stores,id',
            'role_id' => 'required|exists:roles,id',
        ]);

        UserStoreRole::where([
            'user_id' => $user->id,
            'store_id' => $request->store_id,
            'role_id' => $request->role_id,
        ])->delete();

        return response()->json([
            'success' => true,
            'message' => 'Role removed successfully.',
            'data' => $user->getStoresWithRoles(),
        ]);
    }

    // ==========================================
    // LIST STORE USERS (Admin/Manager)
    // ==========================================
    public function storeUsers(Request $request, $storeSlug)
    {
        $user = $request->user();
        $store = \App\Models\Store::where('slug', $storeSlug)->firstOrFail();

        // 1. Base Query
        if ($user->isSuperAdmin()) {
            // Super Admin sees ALL users
            $query = User::with(['storeRoles.store', 'storeRoles.role']);
        } else {
            // Store Admin/Manager sees ONLY users in this store
            $query = User::whereHas('storeRoles', function ($q) use ($store) {
                $q->where('store_id', $store->id);
            })->with([
                        'storeRoles' => function ($q) use ($store) {
                            $q->where('store_id', $store->id)->with('role');
                        }
                    ]);
        }

        // 2. Search
        if ($request->search) {
            $searchTerm = '%' . strtolower($request->search) . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->whereRaw('LOWER(name) LIKE ?', [$searchTerm])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$searchTerm]);
            });
        }

        $users = $query->orderBy('name')->get();

        // 3. Transform
        return response()->json([
            'success' => true,
            'data' => $users->map(function (User $u) use ($user, $store) {
                $data = [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'is_super_admin' => $u->is_super_admin,
                ];

                if ($user->isSuperAdmin()) {
                    // Return detailed role info for Super Admin
                    $data['stores_info'] = $u->storeRoles->map(fn($sr) => [
                        'store' => $sr->store->name,
                        'role' => $sr->role->name
                    ]);
                    // Also flatten for easy display
                    $data['roles_display'] = $u->is_super_admin
                        ? 'Super Admin'
                        : $u->storeRoles->map(fn($sr) => "{$sr->store->name}: {$sr->role->name}")->implode(', ');
                } else {
                    // Return simple roles for this store only
                    $data['roles'] = $u->storeRoles->map(fn($sr) => $sr->role->name);
                    $data['roles_display'] = $u->storeRoles->map(fn($sr) => $sr->role->name)->implode(', ');
                }

                return $data;
            }),
        ]);
    }
}
