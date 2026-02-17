<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'barcode',
        'points',
    ];

    protected $casts = [
        'points' => 'integer',
    ];

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function pointsHistory()
    {
        return $this->hasMany(PointsHistory::class);
    }

    // ==========================================
    // POINTS MANAGEMENT
    // ==========================================

    public function addPoints(int $points, string $type = 'earned', ?int $transactionId = null, ?string $notes = null): void
    {
        $this->increment('points', $points);

        PointsHistory::create([
            'customer_id' => $this->id,
            'type' => $type,
            'points' => $points,
            'balance_after' => $this->points,
            'transaction_id' => $transactionId,
            'notes' => $notes,
            'created_by' => auth()->id(),
        ]);
    }

    public function deductPoints(int $points, string $type = 'redeemed', ?int $transactionId = null, ?string $notes = null): void
    {
        $this->decrement('points', $points);

        PointsHistory::create([
            'customer_id' => $this->id,
            'type' => $type,
            'points' => -$points,
            'balance_after' => $this->points,
            'transaction_id' => $transactionId,
            'notes' => $notes,
            'created_by' => auth()->id(),
        ]);
    }

    public function isMember(): bool
    {
        return !empty($this->barcode);
    }

    // ==========================================
    // SCOPES
    // ==========================================

    public function scopeMembers($query)
    {
        return $query->whereNotNull('barcode');
    }

    public function scopeNonMembers($query)
    {
        return $query->whereNull('barcode');
    }
}
