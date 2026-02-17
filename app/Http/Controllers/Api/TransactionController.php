<?php

namespace App\Http\Controllers\Api;

use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class TransactionController extends Controller
{
    // ==========================================
    // LIST TRANSACTIONS
    // ==========================================
    public function index(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $query = Transaction::where('store_id', $store->id)
            ->with(['user:id,name', 'customer:id,name', 'items']);

        // Filters
        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->date) {
            $query->whereDate('created_at', $request->date);
        }

        if ($request->start_date && $request->end_date) {
            $query->whereBetween('created_at', [
                $request->start_date,
                $request->end_date . ' 23:59:59'
            ]);
        }

        if ($request->customer_id) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->search) {
            $query->where('transaction_number', 'ilike', "%{$request->search}%");
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Pagination
        $perPage = $request->integer('per_page', 15);
        $transactions = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $transactions->items(),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
                'last_page' => $transactions->lastPage(),
            ],
        ]);
    }

    // ==========================================
    // FIND TRANSACTION BY CODE (SCANNER)
    // ==========================================
    public function findByCode(string $storeSlug, string $code)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $transaction = Transaction::where('store_id', $store->id)
            ->where('transaction_number', $code)
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $transaction,
        ]);
    }

    // ==========================================
    // SHOW TRANSACTION
    // ==========================================
    public function show(string $storeSlug, Transaction $transaction)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        if ($transaction->store_id != $store->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $transaction->load(['user', 'customer', 'items', 'voucher', 'store']),
        ]);
    }

    // ==========================================
    // CREATE TRANSACTION (POS)
    // ==========================================
    public function store(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'customer_id' => 'nullable|exists:customers,id',
            'order_type' => 'in:dine_in,takeaway',
            'tax_percentage' => 'nullable|numeric|min:0',
            'delivery_fee' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $store) {
            $taxPercentage = $request->input('tax_percentage', 0);
            $deliveryFee = $request->input('delivery_fee', 0);
            $discountAmount = $request->input('discount_amount', 0);

            $transaction = Transaction::create([
                'store_id' => $store->id,
                'user_id' => auth()->id(),
                'customer_id' => $request->customer_id,
                'transaction_number' => Transaction::generateNumber($store->id),
                'status' => 'pending',
                'order_type' => $request->input('order_type', 'takeaway'),
                'tax_percentage' => $taxPercentage,
                'delivery_fee' => $deliveryFee,
                'discount_amount' => $discountAmount,
            ]);

            $subtotal = 0;
            foreach ($request->items as $item) {
                $product = Product::findOrFail($item['product_id']);
                $itemSubtotal = $product->price * $item['quantity'];

                TransactionItem::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_price' => $product->price,
                    'quantity' => $item['quantity'],
                    'subtotal' => $itemSubtotal,
                ]);

                // Decrease stock immediately
                $product->decreaseStock($item['quantity']);

                $subtotal += $itemSubtotal;
            }

            // Calculate totals
            $taxAmount = $subtotal * ($taxPercentage / 100);
            $totalAmount = max(0, $subtotal + $taxAmount + $deliveryFee - $discountAmount);

            $transaction->update([
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
            ]);

            $this->saveSnapshot($transaction, 'pending');

            return response()->json([
                'success' => true,
                'message' => 'Transaction created.',
                'data' => $transaction->load(['items', 'store']),
            ], 201);
        });
    }

    // ==========================================
    // UPDATE PENDING TRANSACTION
    // ==========================================
    public function update(Request $request, string $storeSlug, Transaction $transaction)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        if ($transaction->store_id != $store->id) {
            \Illuminate\Support\Facades\Log::warning("Transaction Update 404: Tx Store {$transaction->store_id} vs Req Store {$store->id} (Slug: {$storeSlug})");
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        if (!$transaction->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending transactions can be edited.',
            ], 400);
        }

        $request->validate([
            'items' => 'sometimes|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'order_type' => 'in:dine_in,takeaway',
            'notes' => 'nullable|string',
            'tax_percentage' => 'nullable|numeric|min:0|max:100',
            'delivery_fee' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
        ]);

        return DB::transaction(function () use ($request, $transaction) {
            if ($request->has('customer_id')) {
                $transaction->customer_id = $request->customer_id;
            }

            if ($request->has('order_type')) {
                $transaction->order_type = $request->order_type;
            }

            if ($request->has('notes')) {
                $transaction->notes = $request->notes;
            }

            if ($request->has('tax_percentage'))
                $transaction->tax_percentage = $request->tax_percentage;
            if ($request->has('delivery_fee'))
                $transaction->delivery_fee = $request->delivery_fee;
            if ($request->has('discount_amount'))
                $transaction->discount_amount = $request->discount_amount;

            if ($request->has('items')) {
                // Restore stock for old items before deleting
                foreach ($transaction->items as $oldItem) {
                    $product = Product::find($oldItem->product_id);
                    if ($product) {
                        $product->increaseStock($oldItem->quantity);
                    }
                }
                $transaction->items()->delete();

                $subtotal = 0;
                foreach ($request->items as $item) {
                    $product = Product::findOrFail($item['product_id']);
                    $itemSubtotal = $product->price * $item['quantity'];

                    TransactionItem::create([
                        'transaction_id' => $transaction->id,
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_price' => $product->price,
                        'quantity' => $item['quantity'],
                        'subtotal' => $itemSubtotal,
                    ]);

                    // Decrease stock for new items
                    $product->decreaseStock($item['quantity']);

                    $subtotal += $itemSubtotal;
                }

                $transaction->subtotal = $subtotal;
            }

            // Recalculate Total
            $subtotal = $transaction->subtotal;
            $taxPercentage = $transaction->tax_percentage;

            $taxAmount = $subtotal * ($taxPercentage / 100);
            $transaction->tax_amount = round($taxAmount, 2);

            $total = $subtotal + $taxAmount + $transaction->delivery_fee - $transaction->discount_amount;
            $transaction->total_amount = max(0, round($total, 2));

            $transaction->save();

            $this->saveSnapshot($transaction, 'pending');

            return response()->json([
                'success' => true,
                'message' => 'Transaction updated.',
                'data' => $transaction->fresh()->load(['items', 'store']),
            ]);
        });
    }

    // ==========================================
    // ADD ITEM TO PENDING TRANSACTION
    // ==========================================
    public function addItem(Request $request, string $storeSlug, Transaction $transaction)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        if ($transaction->store_id != $store->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        if (!$transaction->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending transactions can be edited.',
            ], 400);
        }

        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $product = Product::findOrFail($request->product_id);

        // Decrease stock immediately
        $product->decreaseStock($request->quantity);

        $itemSubtotal = $product->price * $request->quantity;

        $existingItem = $transaction->items()->where('product_id', $product->id)->first();

        if ($existingItem) {
            $existingItem->quantity += $request->quantity;
            $existingItem->subtotal = $existingItem->quantity * $existingItem->product_price;
            $existingItem->save();
        } else {
            TransactionItem::create([
                'transaction_id' => $transaction->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_price' => $product->price,
                'quantity' => $request->quantity,
                'subtotal' => $itemSubtotal,
            ]);
        }

        $subtotal = $transaction->items()->sum('subtotal');
        $transaction->subtotal = $subtotal;

        // Recalculate Total
        $subtotal = $transaction->items()->sum('subtotal');
        $transaction->subtotal = $subtotal;

        $taxPercentage = $transaction->tax_percentage;
        $taxAmount = $subtotal * ($taxPercentage / 100);
        $transaction->tax_amount = round($taxAmount, 2);

        $total = $subtotal + $taxAmount + $transaction->delivery_fee - $transaction->discount_amount;
        $transaction->total_amount = max(0, round($total, 2));
        $transaction->save();

        $this->saveSnapshot($transaction, 'pending');

        return response()->json([
            'success' => true,
            'message' => 'Item added.',
            'data' => $transaction->fresh()->load(['items', 'store']),
        ]);
    }

    // ==========================================
    // REMOVE ITEM FROM PENDING TRANSACTION
    // ==========================================
    public function removeItem(string $storeSlug, Transaction $transaction, int $itemId)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        if ($transaction->store_id != $store->id) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        if (!$transaction->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending transactions can be edited.',
            ], 400);
        }

        $item = $transaction->items()->find($itemId);
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Item not found'], 404);
        }

        // Restore stock
        $product = Product::find($item->product_id);
        if ($product) {
            $product->increaseStock($item->quantity);
        }

        $item->delete();

        $subtotal = $transaction->items()->sum('subtotal');
        $transaction->subtotal = $subtotal;

        // Recalculate Total
        $taxAmount = $subtotal * ($transaction->tax_percentage / 100);
        $transaction->tax_amount = round($taxAmount, 2);

        $total = $subtotal + $taxAmount + $transaction->delivery_fee - $transaction->discount_amount;
        $transaction->total_amount = max(0, round($total, 2));
        $transaction->save();

        if ($transaction->items()->count() === 0) {
            $this->saveSnapshot($transaction, 'pending');

            return response()->json([
                'success' => true,
                'message' => 'Item removed. Transaction has no items.',
                'data' => $transaction->fresh(),
            ]);
        }

        $this->saveSnapshot($transaction, 'pending');

        return response()->json([
            'success' => true,
            'message' => 'Item removed.',
            'data' => $transaction->fresh()->load(['items', 'store']),
        ]);
    }

    // ==========================================
    // PAY TRANSACTION
    // Supports: voucher, tax, delivery fee, points
    // ==========================================
    public function pay(Request $request, string $storeSlug, Transaction $transaction)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        if ($transaction->store_id != $store->id) {
            \Illuminate\Support\Facades\Log::warning("Transaction Pay 404: Tx Store {$transaction->store_id} vs Req Store {$store->id}");
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        if ($transaction->isPaid()) {
            return response()->json(['success' => false, 'message' => 'Already paid'], 400);
        }

        $request->validate([
            'payment_method' => 'required|in:cash,card,qris,transfer',
            'payment_amount' => 'required|numeric|min:0',
            'points_to_use' => 'nullable|integer|min:0',
            'voucher_code' => 'nullable|string',
            'tax_percentage' => 'nullable|numeric|min:0|max:100',
            'delivery_fee' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0', // Manual discount
        ]);

        return DB::transaction(function () use ($request, $store, $transaction) {
            $subtotal = $transaction->subtotal;
            $customer = $transaction->customer;

            // 1. Calculate tax
            $taxPercentage = $request->input('tax_percentage', $transaction->tax_percentage ?? 0);
            $taxAmount = $subtotal * ($taxPercentage / 100);

            // 2. Delivery fee
            $deliveryFee = $request->input('delivery_fee', $transaction->delivery_fee ?? 0);

            // 3. Voucher discount
            $voucherDiscount = 0;
            $voucherId = null;
            if ($request->voucher_code) {
                $voucher = Voucher::where('store_id', $store->id)
                    ->where('code', strtoupper($request->voucher_code))
                    ->first();

                if ($voucher && $voucher->isValid() && $voucher->isUsable()) {
                    // Check min purchase
                    if ($subtotal >= $voucher->min_purchase) {
                        $voucherDiscount = $voucher->calculateDiscount((float) $subtotal);
                        $voucherId = $voucher->id;
                        $voucher->incrementUsage();
                    }
                }
            }

            // 4. Points discount
            $pointsUsed = 0;
            if ($customer && $request->points_to_use > 0) {
                // Calculation on values
                $subtotalFloat = (float) $subtotal;
                $taxAmountFloat = (float) $taxAmount;
                $deliveryFeeFloat = (float) $deliveryFee;
                $voucherDiscountFloat = (float) $voucherDiscount;

                $maxPointDiscount = $subtotalFloat + $taxAmountFloat + $deliveryFeeFloat - $voucherDiscountFloat;
                $pointsUsed = min($request->points_to_use, $customer->points, $maxPointDiscount);

                if ($pointsUsed > 0) {
                    $customer->deductPoints($pointsUsed, 'redeemed', $transaction->id);
                }
            }

            // 5. Calculate total
            $manualDiscount = $request->input('discount_amount', $transaction->discount_amount ?? 0);
            $totalDiscount = $voucherDiscount + $pointsUsed + $manualDiscount;
            $total = $subtotal + $taxAmount + $deliveryFee - $totalDiscount;
            $change = $request->payment_amount - $total;

            // 6. Earn points (based on subtotal, not total)
            $pointsEarned = 0;
            if ($customer) {
                $pointsRate = $store->getSetting('points_rate', 1000);
                $pointsEarned = floor($subtotal / $pointsRate);
                if ($pointsEarned > 0) {
                    $customer->addPoints($pointsEarned, 'earned', $transaction->id);
                }
            }

            // 7. Decrease stock -> ALREADY DONE IN PENDING STATE
            // Logic removed.

            // 8. Update transaction
            $transaction->update([
                'status' => 'paid',
                'voucher_id' => $voucherId,
                'discount_amount' => $totalDiscount,
                'tax_percentage' => $taxPercentage,
                'tax_amount' => $taxAmount,
                'delivery_fee' => $deliveryFee,
                'points_used' => $pointsUsed,
                'points_earned' => $pointsEarned,
                'total_amount' => max(0, $total),
                'payment_method' => $request->payment_method,
                'payment_amount' => $request->payment_amount,
                'change_amount' => max(0, $change),
                'receipt_snapshot' => null, // Deprecated in favor of file
            ]);

            $this->saveSnapshot($transaction, 'paid');

            return response()->json([
                'success' => true,
                'message' => 'Payment successful.',
                'data' => [
                    'transaction' => $transaction->fresh()->load(['items', 'store']),
                    'summary' => [
                        'subtotal' => $subtotal,
                        'tax' => $taxAmount,
                        'delivery_fee' => $deliveryFee,
                        'voucher_discount' => $voucherDiscount,
                        'points_discount' => $pointsUsed,
                        'total' => max(0, $total),
                        'payment_amount' => $request->payment_amount,
                        'change' => max(0, $change),
                        'points_earned' => $pointsEarned,
                    ],
                ],
            ]);
        });
    }

    // ==========================================
    // CANCEL TRANSACTION
    // ==========================================
    public function cancel(Request $request, string $storeSlug, Transaction $transaction)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        if ($transaction->store_id != $store->id) {
            \Illuminate\Support\Facades\Log::warning("Transaction Cancel 404: Tx Store {$transaction->store_id} vs Req Store {$store->id}");
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        if ($transaction->isCancelled()) {
            return response()->json(['success' => false, 'message' => 'Already cancelled'], 400);
        }

        $request->validate(['reason' => 'required|string']);

        // Restore stock for ALL cancelled transactions (pending or paid)
        foreach ($transaction->items as $item) {
            $product = Product::find($item->product_id);
            if ($product) {
                $product->increaseStock($item->quantity);
            }
        }

        // If was paid, reverse points
        if ($transaction->isPaid()) {

            // Reverse points earned
            if ($transaction->customer && $transaction->points_earned > 0) {
                $transaction->customer->deductPoints(
                    $transaction->points_earned,
                    'adjusted',
                    null,
                    'Cancelled transaction - reversed earned points'
                );
            }

            // Refund points used
            if ($transaction->customer && $transaction->points_used > 0) {
                $transaction->customer->addPoints(
                    $transaction->points_used,
                    'refunded',
                    $transaction->id,
                    'Cancelled transaction - refunded used points'
                );
            }
        }

        $transaction->update([
            'status' => 'cancelled',
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
            'cancellation_reason' => $request->reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transaction cancelled.',
        ]);
    }

    // ==========================================
    // GET RECEIPT
    // ==========================================
    public function receipt(string $storeSlug, Transaction $transaction)
    {
        if (!$transaction->receipt_snapshot) {
            return response()->json(['success' => false, 'message' => 'No receipt'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $transaction->receipt_snapshot,
        ]);
    }

    // ==========================================
    // GROUP BY CUSTOMER
    // ==========================================
    public function groupByCustomer(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $query = Transaction::where('store_id', $store->id)
            ->where('status', 'paid')
            ->whereNotNull('customer_id')
            ->with('customer:id,name,phone,barcode');

        if ($request->start_date && $request->end_date) {
            $query->whereBetween('created_at', [
                $request->start_date,
                $request->end_date . ' 23:59:59'
            ]);
        }

        $grouped = $query->get()
            ->groupBy('customer_id')
            ->map(function ($transactions) {
                $customer = $transactions->first()->customer;
                return [
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'phone' => $customer->phone,
                        'barcode' => $customer->barcode,
                    ],
                    'transaction_count' => $transactions->count(),
                    'total_spent' => $transactions->sum('total_amount'),
                    'transactions' => $transactions->map(fn($t) => [
                        'id' => $t->id,
                        'transaction_number' => $t->transaction_number,
                        'date' => $t->created_at->toDateString(),
                        'total_amount' => $t->total_amount,
                        'status' => $t->status,
                    ]),
                ];
            })
            ->values()
            ->sortByDesc('total_spent')
            ->values();

        return response()->json(['success' => true, 'data' => $grouped]);
    }

    // ==========================================
    // SNAPSHOT HELPER
    // ==========================================
    private function saveSnapshot(Transaction $transaction, string $type)
    {
        try {
            $data = $transaction->load(['items', 'customer', 'user', 'store.settings']);

            // Map store settings to key-value pairs
            $settings = $data->store->settings->pluck('value', 'key')->toArray();

            $snapshot = [
                'transaction' => $data,
                'store' => $data->store,
                'store_settings' => $settings,
                'generated_at' => now()->toIso8601String(),
                'type' => $type // 'pending' (bill) or 'paid' (receipt)
            ];

            $filename = "{$transaction->transaction_number}_{$type}.json";
            // Save to public storage so it can be accessed or downloaded if needed, 
            // but for security maybe strictly via controller.
            // User requested "JSON file to save data".
            \Illuminate\Support\Facades\Storage::disk('local')->put("receipts/{$filename}", json_encode($snapshot, JSON_PRETTY_PRINT));

            return $filename;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to save snapshot: " . $e->getMessage());
            return null;
        }
    }

    // ==========================================
    // GET SNAPSHOT (API)
    // ==========================================
    public function getSnapshot(Request $request, string $storeSlug, string $transactionNumber, string $type)
    {
        // $type should be 'pending' or 'paid'
        if (!in_array($type, ['pending', 'paid'])) {
            return response()->json(['success' => false, 'message' => 'Invalid type'], 400);
        }

        $filename = "{$transactionNumber}_{$type}.json";

        $filename = "{$transactionNumber}_{$type}.json";
        $shouldRegenerate = false;
        $snapshotData = null;

        // 1. Check if file exists
        if (\Illuminate\Support\Facades\Storage::disk('local')->exists("receipts/{$filename}")) {
            $content = \Illuminate\Support\Facades\Storage::disk('local')->get("receipts/{$filename}");
            $snapshotData = json_decode($content, true);

            // 2. Check for staleness (Pending status OR missing/invalid logo URL)
            if ($type === 'pending') {
                $shouldRegenerate = true;
            } elseif (
                empty($snapshotData['store']['logo']) ||
                !filter_var($snapshotData['store']['logo'], FILTER_VALIDATE_URL)
            ) {
                // If logo is present but not a URL, it's a stale snapshot -> Regenerate
                $shouldRegenerate = true;
            }
        } else {
            $shouldRegenerate = true;
        }

        if ($shouldRegenerate) {
            $transaction = Transaction::where('transaction_number', $transactionNumber)
                ->where('store_id', Store::where('slug', $storeSlug)->firstOrFail()->id)
                ->firstOrFail();

            if ($type === 'paid' && !$transaction->isPaid()) {
                return response()->json(['success' => false, 'message' => 'Transaction not paid yet'], 400);
            }

            $newFilename = $this->saveSnapshot($transaction, $type);

            if ($newFilename) {
                $content = \Illuminate\Support\Facades\Storage::disk('local')->get("receipts/{$newFilename}");
                return response()->json([
                    'success' => true,
                    'data' => json_decode($content, true)
                ]);
            }

            return response()->json(['success' => false, 'message' => 'Snapshot could not be generated'], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $snapshotData
        ]);
    }

    // ==========================================
    // GROUP BY PRODUCT
    // ==========================================
    public function groupByProduct(Request $request, string $storeSlug)
    {
        $store = Store::where('slug', $storeSlug)->firstOrFail();

        $query = TransactionItem::join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->where('transactions.store_id', $store->id)
            ->where('transactions.status', 'paid');

        if ($request->start_date && $request->end_date) {
            $query->whereBetween('transactions.created_at', [
                $request->start_date,
                $request->end_date . ' 23:59:59'
            ]);
        }

        $grouped = $query->select(
            'transaction_items.product_id',
            'transaction_items.product_name',
            DB::raw('SUM(transaction_items.quantity) as total_quantity'),
            DB::raw('SUM(transaction_items.subtotal) as total_revenue'),
            DB::raw('COUNT(DISTINCT transactions.id) as transaction_count')
        )
            ->groupBy('transaction_items.product_id', 'transaction_items.product_name')
            ->orderBy('total_revenue', 'desc')
            ->get();

        $result = $grouped->map(function ($item) use ($store, $request) {
            $transactionQuery = Transaction::where('store_id', $store->id)
                ->where('status', 'paid')
                ->whereHas('items', function ($q) use ($item) {
                    $q->where('product_id', $item->product_id);
                });

            if ($request->start_date && $request->end_date) {
                $transactionQuery->whereBetween('created_at', [
                    $request->start_date,
                    $request->end_date . ' 23:59:59'
                ]);
            }

            $transactions = $transactionQuery->with('customer:id,name')
                ->limit(10)
                ->get()
                ->map(fn($t) => [
                    'id' => $t->id,
                    'transaction_number' => $t->transaction_number,
                    'date' => $t->created_at->toDateString(),
                    'customer' => $t->customer ? $t->customer->name : 'Guest',
                    'total_amount' => $t->total_amount,
                ]);

            return [
                'product' => [
                    'id' => $item->product_id,
                    'name' => $item->product_name,
                ],
                'total_quantity' => $item->total_quantity,
                'total_revenue' => $item->total_revenue,
                'transaction_count' => $item->transaction_count,
                'transactions' => $transactions,
            ];
        });

        return response()->json(['success' => true, 'data' => $result]);
    }
}
