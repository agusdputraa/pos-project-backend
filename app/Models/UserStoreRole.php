<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class UserStoreRole extends Pivot
{
    protected $table = 'user_store_roles';

    protected $fillable = [
        'user_id',
        'store_id',
        'role_id',
    ];

    public $timestamps = false;
    const CREATED_AT = 'created_at';

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
