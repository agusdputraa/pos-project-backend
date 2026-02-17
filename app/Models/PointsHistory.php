<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PointsHistory extends Model
{
    public $timestamps = false;
    const CREATED_AT = 'created_at';

    protected $table = 'points_history';

    protected $fillable = [
        'customer_id',
        'type',
        'points',
        'balance_after',
        'transaction_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'points' => 'integer',
        'balance_after' => 'integer',
        'created_at' => 'datetime',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ==========================================
    // HELPERS
    // ==========================================

    public function isEarned(): bool
    {
        return $this->type === 'earned';
    }

    public function isRedeemed(): bool
    {
        return $this->type === 'redeemed';
    }
}
