<?php

namespace App\Http\Controllers\Api;

use App\Models\Voucher;
use App\Models\Store;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;

class VoucherController extends Controller
{
    // ==========================================
    // LIST VOUCHERS
    // ==========================================
    public function index(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $query = Voucher::where('store_id', $store->id);

        if ($request->boolean('active_only')) {
            $query->active();
        }

        $vouchers = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $vouchers,
        ]);
    }

    // ==========================================
    // SHOW VOUCHER
    // ==========================================
    public function show(string $storeSlug, Voucher $voucher)
    {
        return response()->json([
            'success' => true,
            'data' => $voucher,
        ]);
    }

    // ==========================================
    // CREATE VOUCHER
    // ==========================================
    public function store(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'barcode' => 'nullable|string|max:100',
            'type' => 'required|in:percentage,fixed',
            'value' => 'required|numeric|min:0',
            'min_purchase' => 'nullable|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $voucher = Voucher::create([
            'store_id' => $store->id,
            'name' => $request->name,
            'code' => $request->code ?? strtoupper(Str::random(8)),
            'barcode' => $request->barcode, // Auto-generated in model if null
            'type' => $request->type,
            'value' => $request->value,
            'min_purchase' => $request->min_purchase ?? 0,
            'max_discount' => $request->max_discount,
            'usage_limit' => $request->usage_limit,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'is_active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Voucher created.',
            'data' => $voucher,
        ], 201);
    }

    // ==========================================
    // UPDATE VOUCHER
    // ==========================================
    public function update(Request $request, string $storeSlug, Voucher $voucher)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        if ($voucher->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'in:percentage,fixed',
            'value' => 'numeric|min:0',
            'min_purchase' => 'nullable|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'usage_limit' => 'nullable|integer|min:1',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ]);

        $voucher->update($request->only([
            'name',
            'type',
            'value',
            'min_purchase',
            'max_discount',
            'usage_limit',
            'start_date',
            'end_date',
            'is_active',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Voucher updated.',
            'data' => $voucher,
        ]);
    }

    // ==========================================
    // DELETE VOUCHER
    // ==========================================
    public function destroy(string $storeSlug, Voucher $voucher)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        if ($voucher->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $voucher->delete();

        return response()->json([
            'success' => true,
            'message' => 'Voucher deleted.',
        ]);
    }

    // ==========================================
    // FIND VOUCHER BY BARCODE (QR Scan)
    // ==========================================
    public function findByBarcode(Request $request, string $storeSlug, string $barcode)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $voucher = Voucher::where('store_id', $store->id)
            ->where('barcode', $barcode)
            ->first();

        if (!$voucher) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher not found.',
            ], 404);
        }

        $subtotal = $request->input('subtotal', 0);
        $discount = $voucher->calculateDiscount($subtotal);

        return response()->json([
            'success' => true,
            'data' => [
                'voucher' => $voucher,
                'is_valid' => $voucher->isValid(),
                'is_usable' => $voucher->isUsable(),
                'discount' => $discount,
            ],
        ]);
    }

    // ==========================================
    // VALIDATE VOUCHER (by code or barcode)
    // ==========================================
    public function validateVoucher(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $request->validate([
            'code' => 'required_without:barcode|string',
            'barcode' => 'required_without:code|string',
            'subtotal' => 'required|numeric|min:0',
        ]);

        // Find by code or barcode
        $query = Voucher::where('store_id', $store->id);

        if ($request->code) {
            $query->where('code', strtoupper($request->code));
        } elseif ($request->barcode) {
            $query->where('barcode', $request->barcode);
        }

        $voucher = $query->first();

        if (!$voucher) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher not found.',
            ], 404);
        }

        if (!$voucher->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher is not valid or expired.',
            ], 400);
        }

        if (!$voucher->isUsable()) {
            return response()->json([
                'success' => false,
                'message' => 'Voucher usage limit reached.',
            ], 400);
        }

        if ($request->subtotal < $voucher->min_purchase) {
            return response()->json([
                'success' => false,
                'message' => "Minimum purchase is Rp " . number_format($voucher->min_purchase, 0, ',', '.'),
            ], 400);
        }

        $discount = $voucher->calculateDiscount($request->subtotal);

        return response()->json([
            'success' => true,
            'message' => 'Voucher is valid.',
            'data' => [
                'voucher' => $voucher,
                'discount' => $discount,
                'final_total' => $request->subtotal - $discount,
            ],
        ]);
    }

    // ==========================================
    // REDEEM POINTS TO VOUCHER
    // ==========================================
    public function redeemPoints(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'points' => 'required|integer|min:1000',
        ]);

        $customer = Customer::findOrFail($request->customer_id);

        if ($customer->points < $request->points) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient points. Customer has ' . $customer->points . ' points.',
            ], 400);
        }

        $pointsRate = $store->getSetting('points_value_rate', 1);
        $voucherValue = $request->points * $pointsRate;

        $voucher = Voucher::create([
            'store_id' => $store->id,
            'name' => 'Points Redemption - ' . $customer->name,
            'code' => 'PTS' . strtoupper(Str::random(6)),
            // barcode auto-generated
            'type' => 'fixed',
            'value' => $voucherValue,
            'min_purchase' => 0,
            'max_discount' => null,
            'usage_limit' => 1,
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'is_active' => true,
        ]);

        $customer->deductPoints($request->points, 'redeemed', null, 'Redeemed for voucher ' . $voucher->code);

        return response()->json([
            'success' => true,
            'message' => 'Voucher created from points.',
            'data' => [
                'voucher' => $voucher,
                'points_used' => $request->points,
                'customer_remaining_points' => $customer->fresh()->points,
            ],
        ], 201);
    }
}
