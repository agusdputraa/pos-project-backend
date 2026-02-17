<?php

namespace App\Http\Controllers\Api;

use App\Models\Store;
use App\Models\Transaction;
use App\Models\Product;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class DashboardController extends Controller
{
    // ==========================================
    // DASHBOARD OVERVIEW
    // ==========================================
    public function index(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();
        $date = $request->input('date', now()->toDateString());

        // Today's stats
        $todayTransactions = Transaction::where('store_id', $store->id)
            ->whereDate('created_at', $date)
            ->where('status', 'paid');

        // This month stats
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $monthTransactions = Transaction::where('store_id', $store->id)
            ->whereBetween('created_at', [$monthStart, $monthEnd . ' 23:59:59'])
            ->where('status', 'paid');

        // Pending transactions
        $pendingCount = Transaction::where('store_id', $store->id)
            ->where('status', 'pending')
            ->count();

        // Low stock products
        $lowStockProducts = Product::where('store_id', $store->id)
            ->active()
            ->where('stock', '<=', 10)
            ->orderBy('stock')
            ->limit(5)
            ->get(['id', 'name', 'stock', 'price']);

        // Recent transactions
        $recentTransactions = Transaction::where('store_id', $store->id)
            ->with(['user:id,name', 'customer:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'today' => [
                    'date' => $date,
                    'transaction_count' => $todayTransactions->count(),
                    'revenue' => $todayTransactions->sum('total_amount'),
                    'discount' => $todayTransactions->sum('discount_amount'),
                ],
                'month' => [
                    'period' => "$monthStart to $monthEnd",
                    'transaction_count' => $monthTransactions->count(),
                    'revenue' => $monthTransactions->sum('total_amount'),
                ],
                'pending_transactions' => $pendingCount,
                'low_stock_products' => $lowStockProducts,
                'recent_transactions' => $recentTransactions,
                'store' => [
                    'name' => $store->name,
                    'type' => $store->type,
                    'product_count' => Product::where('store_id', $store->id)->active()->count(),
                    'customer_count' => Customer::count(),
                ],
            ],
        ]);
    }
}
