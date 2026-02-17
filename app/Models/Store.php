<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Store extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'type',
        'address',
        'phone',
        'logo',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_store_roles')
            ->withPivot('role_id')
            ->using(UserStoreRole::class);
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    public function settings()
    {
        return $this->hasMany(StoreSetting::class);
    }

    public function attendances()
    {
        return $this->hasMany(Attendance::class);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    public function isCafeOrRestaurant(): bool
    {
        return in_array($this->type, ['cafe', 'restaurant']);
    }

    public function isMarket(): bool
    {
        return in_array($this->type, ['market', 'minimarket']);
    }

    public function getSetting(string $key, $default = null)
    {
        $setting = $this->settings()->where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    public function setSetting(string $key, $value): void
    {
        $this->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Get current time in store's timezone.
     */
    public function getCurrentTime(): Carbon
    {
        $timezone = $this->getSetting('timezone', 'Asia/Jakarta');
        return Carbon::now($timezone);
    }

    /**
     * Get store opening time in store's timezone.
     */
    public function getOpeningTime(): Carbon
    {
        $timezone = $this->getSetting('timezone', 'Asia/Jakarta');
        $time = $this->getSetting('store_opening_time', '08:00');
        return Carbon::parse($time, $timezone);
    }
    public function getLogoAttribute($value)
    {
        if (!$value)
            return null;
        if (filter_var($value, FILTER_VALIDATE_URL))
            return $value;

        // Ensure we're using the public disk URL generation
        return asset('storage/' . ltrim($value, '/'));
    }
}
