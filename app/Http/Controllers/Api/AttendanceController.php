<?php

namespace App\Http\Controllers\Api;

use App\Models\Attendance;
use App\Models\Store;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    // ==========================================
    // LIST ATTENDANCE (Admin/Manager)
    // ==========================================
    public function index(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $query = Attendance::where('store_id', $store->id)
            ->with('user:id,name,email')
            ->orderBy('date', 'desc');

        if ($request->has('date')) {
            $query->whereDate('date', $request->date);
        }

        $attendances = $query->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $attendances,
        ]);
    }

    // ==========================================
    // TODAY'S ATTENDANCE (Current User)
    // ==========================================
    public function today(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();
        $user = $request->user();
        $today = Carbon::today()->toDateString();

        $attendance = Attendance::where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->whereDate('date', $today)
            ->first();

        return response()->json([
            'success' => true,
            'data' => $attendance ? [
                'id' => $attendance->id,
                'clock_in' => $attendance->clock_in,
                'clock_out' => $attendance->clock_out,
                'is_late' => $attendance->is_late,
                'notes' => $attendance->notes,
                'date' => $attendance->date->format('Y-m-d'),
            ] : null,
        ]);
    }

    // ==========================================
    // CHECK IN
    // ==========================================
    public function checkIn(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();
        $user = $request->user();

        // Use store's timezone
        $now = $store->getCurrentTime();
        $today = $now->toDateString();

        // Check if already checked in
        $existing = Attendance::where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->whereDate('date', $today)
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'You have already checked in today.',
            ], 422);
        }

        $attendance = Attendance::create([
            'user_id' => $user->id,
            'store_id' => $store->id,
            'date' => $today,
            'clock_in' => $now->format('H:i:s'),
        ]);

        // Calculate late status
        $isLate = $attendance->calculateLateStatus($store);
        $attendance->update(['is_late' => $isLate]);

        return response()->json([
            'success' => true,
            'message' => 'Checked in successfully' . ($isLate ? ' (Late)' : ''),
            'data' => [
                'id' => $attendance->id,
                'clock_in' => $attendance->clock_in,
                'clock_out' => $attendance->clock_out,
                'is_late' => $isLate,
                'date' => $attendance->date->format('Y-m-d'),
            ],
        ]);
    }

    // ==========================================
    // CHECK OUT
    // ==========================================
    public function checkOut(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();
        $user = $request->user();

        // Use store's timezone
        $now = $store->getCurrentTime();
        $today = $now->toDateString();

        $attendance = Attendance::where('user_id', $user->id)
            ->where('store_id', $store->id)
            ->whereDate('date', $today)
            ->first();

        if (!$attendance || !$attendance->clock_in) {
            return response()->json([
                'success' => false,
                'message' => 'You have not checked in today.',
            ], 422);
        }

        if ($attendance->clock_out) {
            return response()->json([
                'success' => false,
                'message' => 'You have already checked out today.',
            ], 422);
        }

        $attendance->update(['clock_out' => $now->format('H:i:s')]);

        return response()->json([
            'success' => true,
            'message' => 'Checked out successfully.',
            'data' => [
                'id' => $attendance->id,
                'clock_in' => $attendance->clock_in,
                'clock_out' => $attendance->clock_out,
                'is_late' => $attendance->is_late,
                'date' => $attendance->date->format('Y-m-d'),
            ],
        ]);
    }

    // ==========================================
    // ACTIVE STAFF (Manager/Admin view)
    // ==========================================
    public function activeStaff(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();
        $today = Carbon::today()->toDateString();

        $attendances = Attendance::where('store_id', $store->id)
            ->whereDate('date', $today)
            ->whereNotNull('clock_in')
            ->with('user:id,name,email')
            ->orderBy('clock_in', 'asc')
            ->get();

        $data = $attendances->map(function ($att) {
            return [
                'user_id' => $att->user_id,
                'name' => $att->user->name ?? 'Unknown',
                'email' => $att->user->email ?? '',
                'clock_in' => $att->clock_in,
                'clock_out' => $att->clock_out,
                'is_late' => $att->is_late,
                'is_online' => $att->isClockedIn(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // ==========================================
    // SHOW SINGLE ATTENDANCE
    // ==========================================
    public function show(Request $request, string $storeSlug, Attendance $attendance)
    {
        return response()->json([
            'success' => true,
            'data' => $attendance->load('user:id,name,email'),
        ]);
    }
}
