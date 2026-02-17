<?php

namespace App\Http\Controllers\Api;

use App\Models\Store;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ReportController extends Controller
{
    // ==========================================
    // DAILY SUMMARY
    // ==========================================
    public function dailySummary(Request $request, string $storeSlug)
    {
        try {
            $store = Store::where('slug', $storeSlug)->firstOrFail();
            $date = $request->input('date', now()->toDateString());

            $transactions = Transaction::where('store_id', $store->id)
                ->whereDate('created_at', $date)
                ->where('status', 'paid');

            $byPaymentMethod = Transaction::where('store_id', $store->id)
                ->whereDate('created_at', $date)
                ->where('status', 'paid')
                ->selectRaw('payment_method, COUNT(*) as count, SUM(total_amount) as total')
                ->groupBy('payment_method')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $date,
                    'total_transactions' => $transactions->count(),
                    'total_revenue' => $transactions->sum('total_amount'),
                    'total_discount' => $transactions->sum('discount_amount'),
                    'by_payment_method' => $byPaymentMethod,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ==========================================
    // SALES REPORT (Date Range)
    // ==========================================
    public function salesReport(Request $request, string $storeSlug)
    {
        try {
            $store = Store::where('slug', $storeSlug)->firstOrFail();

            $request->validate([
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
            ]);

            $query = Transaction::where('store_id', $store->id)
                ->where('status', 'paid')
                ->whereBetween('created_at', [
                    $request->start_date,
                    $request->end_date . ' 23:59:59'
                ]);

            $daily = Transaction::where('store_id', $store->id)
                ->where('status', 'paid')
                ->whereBetween('created_at', [
                    $request->start_date,
                    $request->end_date . ' 23:59:59'
                ])
                ->selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(total_amount) as total')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date,
                    'summary' => [
                        'total_transactions' => (clone $query)->count(),
                        'total_revenue' => (clone $query)->sum('total_amount'),
                        'unique_customers' => (clone $query)->whereNotNull('customer_id')->distinct('customer_id')->count('customer_id'),
                        'unique_products' => TransactionItem::whereHas('transaction', function ($q) use ($store, $request) {
                            $q->where('store_id', $store->id)
                                ->where('status', 'paid')
                                ->whereBetween('created_at', [
                                    $request->start_date,
                                    $request->end_date . ' 23:59:59'
                                ]);
                        })->distinct('product_id')->count('product_id'),
                    ],
                    'daily' => $daily,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ==========================================
    // TOP PRODUCTS
    // ==========================================
    public function topProducts(Request $request, string $storeSlug)
    {
        try {
            $store = Store::where('slug', $storeSlug)->firstOrFail();
            $limit = $request->integer('limit', 10);
            $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
            $endDate = $request->input('end_date', now()->toDateString());

            $products = TransactionItem::join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
                ->where('transactions.store_id', $store->id)
                ->where('transactions.status', 'paid')
                ->whereBetween('transactions.created_at', [$startDate, $endDate . ' 23:59:59'])
                ->selectRaw('transaction_items.product_id, transaction_items.product_name, SUM(transaction_items.quantity) as qty_sold, SUM(transaction_items.subtotal) as revenue')
                ->groupBy('transaction_items.product_id', 'transaction_items.product_name')
                ->orderBy('qty_sold', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $products,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ==========================================
    // TOP CUSTOMERS
    // ==========================================
    public function topCustomers(Request $request, string $storeSlug)
    {
        try {
            $store = Store::where('slug', $storeSlug)->firstOrFail();
            $limit = $request->integer('limit', 10);

            $customers = Transaction::where('store_id', $store->id)
                ->where('status', 'paid')
                ->whereNotNull('customer_id')
                ->join('customers', 'transactions.customer_id', '=', 'customers.id')
                ->selectRaw('customers.id, customers.name, COUNT(*) as visit_count, SUM(transactions.total_amount) as total_spent')
                ->groupBy('customers.id', 'customers.name')
                ->orderBy('total_spent', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $customers,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    // ==========================================
    // INVENTORY ANALYSIS
    // ==========================================
    public function inventoryAnalysis(Request $request, string $storeSlug)
    {
        try {
            $store = Store::where('slug', $storeSlug)->firstOrFail();

            // Total products
            $totalProducts = \App\Models\Product::where('store_id', $store->id)
                ->where('is_active', true)
                ->count();

            // Low stock (<= 10)
            $lowStockItems = \App\Models\Product::where('store_id', $store->id)
                ->where('is_active', true)
                ->where('stock', '<=', 10)
                ->orderBy('stock', 'asc')
                ->get(['id', 'name', 'stock', 'price', 'barcode', 'category_id']);

            $lowStockCount = $lowStockItems->count();

            // Out of stock
            $outOfStockCount = $lowStockItems->where('stock', 0)->count();

            // Inventory Value (Retail Price)
            // Note: Efficiently sum using DB
            $totalValue = \App\Models\Product::where('store_id', $store->id)
                ->where('is_active', true)
                ->sum(\Illuminate\Support\Facades\DB::raw('stock * price'));

            return response()->json([
                'success' => true,
                'data' => [
                    'total_products' => $totalProducts,
                    'low_stock_count' => $lowStockCount,
                    'out_of_stock_count' => $outOfStockCount,
                    'total_valuation' => $totalValue,
                    'low_stock_items' => $lowStockItems,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
