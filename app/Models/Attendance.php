<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Attendance extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
        'date',
        'clock_in',
        'clock_out',
        'is_late',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'is_late' => 'boolean',
    ];

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

    // ==========================================
    // HELPERS
    // ==========================================

    public function clockIn(): void
    {
        $this->update(['clock_in' => now()->format('H:i:s')]);
    }

    public function clockOut(): void
    {
        $this->update(['clock_out' => now()->format('H:i:s')]);
    }

    public function isClockedIn(): bool
    {
        return $this->clock_in !== null && $this->clock_out === null;
    }

    public function getDurationAttribute(): ?int
    {
        if (!$this->clock_in || !$this->clock_out) {
            return null;
        }
        return strtotime($this->clock_out) - strtotime($this->clock_in);
    }

    /**
     * Calculate if check-in is late based on store settings.
     */
    public function calculateLateStatus(Store $store): bool
    {
        if (!$this->clock_in) {
            return false;
        }

        $timezone = $store->getSetting('timezone', 'Asia/Jakarta');
        $openingTime = $store->getSetting('store_opening_time', '08:00');
        $tolerance = (int) $store->getSetting('late_tolerance_minutes', 30);

        // Parse clock_in time in store's timezone
        $dateStr = $this->date instanceof \Carbon\Carbon ? $this->date->format('Y-m-d') : $this->date;
        $clockInTime = Carbon::parse($dateStr . ' ' . $this->clock_in, $timezone);

        // Calculate late threshold
        $lateThreshold = Carbon::parse($dateStr . ' ' . $openingTime, $timezone)
            ->addMinutes($tolerance);

        return $clockInTime->greaterThan($lateThreshold);
    }
}
