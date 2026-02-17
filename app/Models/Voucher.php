<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Voucher extends Model
{
    protected $fillable = [
        'store_id',
        'code',
        'barcode',
        'name',
        'type',
        'value',
        'min_purchase',
        'max_discount',
        'usage_limit',
        'used_count',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_purchase' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    // ==========================================
    // BOOT
    // ==========================================

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($voucher) {
            // Auto-generate barcode if not provided
            if (empty($voucher->barcode)) {
                $voucher->barcode = 'VCH' . strtoupper(Str::random(10));
            }
        });
    }

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    // ==========================================
    // HELPERS
    // ==========================================

    public function isValid(): bool
    {
        $today = now()->toDateString();
        return $this->is_active
            && $this->start_date <= $today
            && $this->end_date >= $today;
    }

    public function isUsable(): bool
    {
        return $this->usage_limit === null || $this->used_count < $this->usage_limit;
    }

    public function calculateDiscount(float $amount): float
    {
        if ($amount < $this->min_purchase) {
            return 0;
        }

        if ($this->type === 'percentage') {
            $discount = $amount * ($this->value / 100);
        } else {
            $discount = $this->value;
        }

        if ($this->max_discount !== null) {
            $discount = min($discount, $this->max_discount);
        }

        return $discount;
    }

    public function incrementUsage(): void
    {
        $this->increment('used_count');
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeValid($query)
    {
        $today = now()->toDateString();
        return $query->where('is_active', true)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->where(function ($q) {
                $q->whereNull('usage_limit')
                    ->orWhereColumn('used_count', '<', 'usage_limit');
            });
    }
}
