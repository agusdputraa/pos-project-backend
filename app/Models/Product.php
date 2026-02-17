<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'store_id',
        'category_id',
        'name',
        'description',
        'price',
        'stock',
        'barcode',
        'image',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'is_active' => 'boolean',
    ];

    // Default is_active to true for new products (PostgreSQL-compatible)
    protected static function booted()
    {
        static::creating(function ($product) {
            if (!isset($product->attributes['is_active'])) {
                // Use DB::raw to ensure PostgreSQL gets proper TRUE literal
                $product->is_active = \Illuminate\Support\Facades\DB::raw('true');
            } else {
                // Convert any existing value to PostgreSQL-compatible boolean
                $product->is_active = \Illuminate\Support\Facades\DB::raw(
                    filter_var($product->is_active, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false'
                );
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

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function transactionItems()
    {
        return $this->hasMany(TransactionItem::class);
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeActive($query)
    {
        return $query->whereRaw('is_active = true');
    }

    public function scopeInStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    // ==========================================
    // STOCK MANAGEMENT
    // ==========================================

    public function decreaseStock(int $quantity): void
    {
        $this->decrement('stock', $quantity);
    }

    public function increaseStock(int $quantity): void
    {
        $this->increment('stock', $quantity);
    }

    public function isLowStock(int $threshold = 10): bool
    {
        return $this->stock <= $threshold;
    }
}
