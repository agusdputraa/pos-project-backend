<?php

namespace App\Http\Controllers\Api;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class SearchController extends Controller
{
    public function index(Request $request, string $storeSlug)
    {
        $query = $request->input('q');

        if (!$query) {
            return response()->json([
                'success' => true,
                'data' => [
                    'products' => [],
                    'categories' => [],
                    'transactions' => [],
                    'customers' => [],
                ]
            ]);
        }

        $store = Store::where('slug', $storeSlug)->firstOrFail();
        $storeId = $store->id;

        $like = 'LIKE';
        $term = strtolower($query);

        // 1. Search Products
        $products = Product::where('store_id', $storeId)
            ->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(barcode) LIKE ?', ["%{$term}%"])
                    ->orWhereRaw('LOWER(description) LIKE ?', ["%{$term}%"]);
            })
            ->with('category')
            ->limit(10)
            ->get();

        // 2. Search Categories
        $categories = Category::where('store_id', $storeId)
            ->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
            ->limit(5)
            ->get();

        // 3. Search Transactions (by ID or Product Name in items)
        $transactions = Transaction::where('store_id', $storeId)
            ->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(transaction_number) LIKE ?', ["%{$term}%"])
                    ->orWhereHas('items.product', function ($subQ) use ($term) {
                        $subQ->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"]);
                    });
            })
            ->with(['customer:id,name', 'user:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // 4. Search Customers
        $customers = Customer::where(function ($q) use ($term) {
            $q->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
                ->orWhereRaw('LOWER(email) LIKE ?', ["%{$term}%"])
                ->orWhereRaw('LOWER(phone) LIKE ?', ["%{$term}%"]);
        })
            ->limit(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $products,
                'categories' => $categories,
                'transactions' => $transactions,
                'customers' => $customers,
            ]
        ]);
    }
}
