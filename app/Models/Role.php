<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = [
        'name',
        'guard_name',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_store_roles')
            ->withPivot('store_id')
            ->using(UserStoreRole::class);
    }

    // ==========================================
    // CONSTANTS
    // ==========================================

    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN = 'admin';
    public const MANAGER = 'manager';
    public const CASHIER = 'cashier';
}
