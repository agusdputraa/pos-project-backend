<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $store_id
 * @property int $user_id
 * @property int|null $customer_id
 * @property int|null $voucher_id
 * @property string $transaction_number
 * @property string $status
 * @property string $order_type
 * @property string|float $subtotal
 * @property string|float $discount_amount
 * @property string|float $tax_percentage
 * @property string|float $tax_amount
 * @property string|float $delivery_fee
 * @property int $points_used
 * @property int $points_earned
 * @property string|float $total_amount
 * @property string $payment_method
 * @property string|float $payment_amount
 * @property string|float $change_amount
 * @property string|null $notes
 * @property array|null $receipt_snapshot
 * @property int|null $cancelled_by
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\Store $store
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Customer|null $customer
 * @property-read \App\Models\Voucher|null $voucher
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\TransactionItem[] $items
 */
class Transaction extends Model
{
    protected $fillable = [
        'store_id',
        'user_id',
        'customer_id',
        'voucher_id',
        'transaction_number',
        'status',
        'order_type',
        'subtotal',
        'discount_amount',
        'tax_percentage',
        'tax_amount',
        'delivery_fee',
        'points_used',
        'points_earned',
        'total_amount',
        'payment_method',
        'payment_amount',
        'change_amount',
        'notes',
        'receipt_snapshot',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'payment_amount' => 'decimal:2',
        'change_amount' => 'decimal:2',
        'points_used' => 'integer',
        'points_earned' => 'integer',
        'receipt_snapshot' => 'array',
        'cancelled_at' => 'datetime',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function items()
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function cancelledByUser()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // ==========================================
    // HELPERS
    // ==========================================

    public static function generateNumber(int $storeId): string
    {
        $date = now()->format('Ymd');
        $prefix = "TRX-{$storeId}-{$date}";
        $count = static::where('transaction_number', 'like', "{$prefix}%")->count() + 1;
        return $prefix . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Calculate total with tax, fees, and discounts.
     */
    public function calculateTotal(
        float $taxPercentage = 0,
        float $deliveryFee = 0,
        float $voucherDiscount = 0,
        int $pointsUsed = 0
    ): float {
        $taxAmount = $this->subtotal * ($taxPercentage / 100);
        return $this->subtotal + $taxAmount + $deliveryFee - $voucherDiscount - $pointsUsed;
    }

    public function generateReceiptSnapshot(): array
    {
        $store = $this->store;
        return [
            'store' => [
                'name' => $store->name,
                'address' => $store->address,
                'phone' => $store->phone,
            ],
            'transaction' => [
                'number' => $this->transaction_number,
                'date' => $this->created_at->toIso8601String(),
                'cashier' => $this->user->name,
                'order_type' => $this->order_type,
            ],
            'items' => $this->items->map(fn($i) => [
                'name' => $i->product_name,
                'qty' => $i->quantity,
                'price' => $i->product_price,
                'subtotal' => $i->subtotal,
            ])->toArray(),
            'subtotal' => $this->subtotal,
            'tax' => [
                'percentage' => $this->tax_percentage,
                'amount' => $this->tax_amount,
            ],
            'delivery_fee' => $this->delivery_fee,
            'discount' => $this->discount_amount,
            'voucher' => $this->voucher ? [
                'code' => $this->voucher->code,
                'discount' => $this->discount_amount,
            ] : null,
            'points_used' => $this->points_used,
            'total' => $this->total_amount,
            'payment' => [
                'method' => $this->payment_method,
                'amount' => $this->payment_amount,
                'change' => $this->change_amount,
            ],
            'customer' => $this->customer ? [
                'name' => $this->customer->name,
                'points_earned' => $this->points_earned,
                'points_used' => $this->points_used,
            ] : ['name' => 'Guest Customer'],
        ];
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }
}
