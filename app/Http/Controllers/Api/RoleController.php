<?php

namespace App\Http\Controllers\Api;

use App\Models\Role;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class RoleController extends Controller
{
    public function index()
    {
        // Exclude Super Admin role if it exists in the roles table, 
        // as super admin is a global flag on user, not a store role usually.
        // But if 'super_admin' is in roles table, we might want to exclude it from store assignment.
        // Based on User model, is_super_admin is a flag. 
        // Store roles are likely: admin, manager, cashier.

        $roles = Role::where('name', '!=', 'super_admin')->get();

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }
}
