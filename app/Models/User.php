<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Store;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_super_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function storeRoles()
    {
        return $this->hasMany(UserStoreRole::class);
    }

    public function stores()
    {
        return $this->belongsToMany(Store::class, 'user_store_roles')
            ->withPivot('role_id')
            ->using(UserStoreRole::class);
    }

    public function preferences()
    {
        return $this->hasMany(UserPreference::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin == true;
    }

    public function hasAccessToStore(int $storeId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        return $this->storeRoles()->where('store_id', $storeId)->exists();
    }

    public function getRolesInStore(int $storeId): array
    {
        return $this->storeRoles()
            ->where('store_id', $storeId)
            ->with('role')
            ->get()
            ->pluck('role.name')
            ->toArray();
    }

    public function hasRoleInStore(string $roleName, int $storeId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        return $this->storeRoles()
            ->where('store_id', $storeId)
            ->whereHas('role', fn($q) => $q->where('name', $roleName))
            ->exists();
    }

    public function getPreference(string $key, $default = null)
    {
        $pref = $this->preferences()->where('key', $key)->first();
        return $pref ? $pref->value : $default;
    }

    public function setPreference(string $key, $value): void
    {
        $this->preferences()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    // Get stores with roles for frontend (role names as strings for auth checks)
    public function getStoresWithRoles(): array
    {
        $storesMap = collect();

        $this->storeRoles()->with(['store', 'role'])->get()->each(function ($usr) use ($storesMap) {
            if (!$usr->store)
                return;

            $storeId = $usr->store->id;

            if (!$storesMap->has($storeId)) {
                $storesMap->put($storeId, [
                    'id' => $usr->store->id,
                    'name' => $usr->store->name ?? 'Unknown Store',
                    'slug' => $usr->store->slug ?? '',
                    'logo' => $usr->store->logo,
                    'type' => $usr->store->type,
                    'roles' => [],
                ]);
            }

            if ($usr->role) {
                $storeData = $storesMap->get($storeId);
                if (!in_array($usr->role->name, $storeData['roles'])) {
                    $storeData['roles'][] = $usr->role->name;
                    $storesMap->put($storeId, $storeData);
                }
            }
        });

        if ($this->isSuperAdmin()) {
            try {
                $allStores = Store::orderBy('name')->get();
                foreach ($allStores as $store) {
                    if (!$storesMap->has($store->id)) {
                        $storesMap->put($store->id, [
                            'id' => $store->id,
                            'name' => $store->name ?? 'Unknown Store',
                            'slug' => $store->slug ?? '',
                            'logo' => $store->logo,
                            'type' => $store->type,
                            'roles' => ['super_admin'],
                        ]);
                    }
                }
            } catch (\Exception $e) {
            }
        }

        return $storesMap->sortBy(fn($s) => strtolower($s['name']))->values()->all();
    }

    // Get stores with detailed role objects (id + name) for user management UI
    public function getStoresWithDetailedRoles(): array
    {
        $storesMap = collect();

        $this->storeRoles()->with(['store', 'role'])->get()->each(function ($usr) use ($storesMap) {
            if (!$usr->store)
                return;

            $storeId = $usr->store->id;

            if (!$storesMap->has($storeId)) {
                $storesMap->put($storeId, [
                    'id' => $usr->store->id,
                    'name' => $usr->store->name ?? 'Unknown Store',
                    'slug' => $usr->store->slug ?? '',
                    'logo' => $usr->store->logo,
                    'type' => $usr->store->type,
                    'roles' => [],
                ]);
            }

            if ($usr->role) {
                $storeData = $storesMap->get($storeId);
                $existingIds = array_column($storeData['roles'], 'id');
                if (!in_array($usr->role->id, $existingIds)) {
                    $storeData['roles'][] = [
                        'id' => $usr->role->id,
                        'name' => $usr->role->name,
                    ];
                    $storesMap->put($storeId, $storeData);
                }
            }
        });

        return $storesMap->sortBy(fn($s) => strtolower($s['name']))->values()->all();
    }
}
