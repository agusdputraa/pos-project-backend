<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class CustomerController extends Controller
{
    // ==========================================
    // LIST CUSTOMERS
    // ==========================================
    // ==========================================
    // LIST CUSTOMERS
    // ==========================================
    public function index(Request $request, string $storeSlug)
    {
        $query = Customer::query();

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'ilike', "%{$request->search}%")
                    ->orWhere('phone', 'ilike', "%{$request->search}%")
                    ->orWhere('barcode', 'ilike', "%{$request->search}%");
            });
        }

        if ($request->filter === 'members') {
            $query->members();
        } elseif ($request->filter === 'non-members') {
            $query->nonMembers();
        }

        $customers = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $customers,
        ]);
    }

    // ==========================================
    // SHOW CUSTOMER
    // ==========================================
    public function show(string $storeSlug, Customer $customer)
    {
        return response()->json([
            'success' => true,
            'data' => $customer->load('pointsHistory'),
        ]);
    }

    // ==========================================
    // CREATE CUSTOMER
    // ==========================================
    public function store(Request $request, string $storeSlug)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:customers,phone',
            'email' => 'nullable|email|unique:customers,email',
            'address' => 'nullable|string',
            'barcode' => 'nullable|string|max:50|unique:customers,barcode',
        ]);

        $barcode = $request->barcode;
        if (!$barcode) {
            $barcode = 'CUST-' . time() . rand(100, 999);
        }

        $customer = Customer::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'address' => $request->address,
            'barcode' => $barcode,
            'points' => 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully.',
            'data' => $customer,
        ], 201);
    }

    // ==========================================
    // UPDATE CUSTOMER
    // ==========================================
    public function update(Request $request, string $storeSlug, Customer $customer)
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20|unique:customers,phone,' . $customer->id,
            'email' => 'nullable|email|unique:customers,email,' . $customer->id,
            'address' => 'nullable|string',
            'barcode' => 'nullable|string|max:50|unique:customers,barcode,' . $customer->id,
        ]);

        $customer->update($request->only([
            'name',
            'phone',
            'email',
            'address',
            'barcode'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully.',
            'data' => $customer->fresh(),
        ]);
    }

    // ==========================================
    // DELETE CUSTOMER
    // ==========================================
    public function destroy(string $storeSlug, Customer $customer)
    {
        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Customer deleted successfully.',
        ]);
    }

    // ==========================================
    // ADJUST POINTS (Admin & Manager)
    // Positive = add, Negative = deduct
    // ==========================================
    public function adjustPoints(Request $request, Customer $customer)
    {
        // TODO: Add middleware to check if user is Admin or Manager

        $request->validate([
            'points' => 'required|integer',
            'reason' => 'required|string|max:255',
        ]);

        if ($request->points > 0) {
            $customer->addPoints($request->points, 'adjusted', null, $request->reason);
            $action = 'added';
        } else {
            $customer->deductPoints(abs($request->points), 'adjusted', null, $request->reason);
            $action = 'deducted';
        }

        return response()->json([
            'success' => true,
            'message' => ucfirst($action) . ' ' . abs($request->points) . ' points.',
            'data' => $customer->fresh()->load('pointsHistory'),
        ]);
    }

    // ==========================================
    // FIND BY BARCODE
    // ==========================================
    public function findByBarcode(string $storeSlug, string $barcode)
    {
        $customer = Customer::where('barcode', $barcode)->first();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $customer,
        ]);
    }

    // ==========================================
    // SEARCH CUSTOMERS (For autocomplete)
    // ==========================================
    public function search(Request $request)
    {
        $query = $request->input('q', '');

        if (strlen($query) < 2) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $customers = Customer::where('name', 'ilike', "%{$query}%")
            ->orWhere('phone', 'ilike', "%{$query}%")
            ->limit(10)
            ->get(['id', 'name', 'phone', 'points', 'barcode']);

        return response()->json([
            'success' => true,
            'data' => $customers,
        ]);
    }
}
