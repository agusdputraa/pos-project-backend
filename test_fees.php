<?php

use App\Models\Store;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// 1. Setup Data
$store = Store::first();
$user = User::first();
$product = Product::where('store_id', $store->id)->first();

echo "Testing with Store: {$store->name}\n";

// 2. Create Transaction
$transaction = Transaction::create([
    'store_id' => $store->id,
    'user_id' => $user->id,
    'transaction_number' => 'TEST-' . time(),
    'subtotal' => $product->price,
    'total_amount' => $product->price,
    'status' => 'pending',
    'payment_method' => 'cash',
    'payment_amount' => 0,
    'change_amount' => 0,
]);

TransactionItem::create([
    'transaction_id' => $transaction->id,
    'product_id' => $product->id,
    'product_name' => $product->name,
    'product_price' => $product->price,
    'quantity' => 1,
    'subtotal' => $product->price,
]);

// 3. Update with Tax & Delivery Fee (Simulating Payment Page Save)
$taxPercentage = 10;
$deliveryFee = 5000;
$subtotal = $transaction->subtotal;
$taxAmount = $subtotal * ($taxPercentage / 100);
$total = $subtotal + $taxAmount + $deliveryFee;

$transaction->update([
    'tax_percentage' => $taxPercentage,
    'tax_amount' => $taxAmount,
    'delivery_fee' => $deliveryFee,
    'total_amount' => $total
]);

echo "Transaction Updated (Pending): Tax={$transaction->tax_amount}, Fee={$transaction->delivery_fee}, Total={$transaction->total_amount}\n";

// 4. Simulate Payment (without sending tax/fee again)
// This mimics the frontend calling /pay just with method and amount
// We want to ensure tax/fee are NOT reset to 0
$request = new \Illuminate\Http\Request();
$request->replace([
    'payment_method' => 'cash',
    'payment_amount' => $total
]);

$controller = new \App\Http\Controllers\TransactionController();
$response = $controller->pay($request, $store->slug, $transaction);

$data = $response->getData(true);

if ($data['success']) {
    $updatedTxn = Transaction::find($transaction->id);
    echo "Payment Success.\n";
    echo "Final Transaction: Tax={$updatedTxn->tax_amount}, Fee={$updatedTxn->delivery_fee}, Total={$updatedTxn->total_amount}\n";

    if ($updatedTxn->delivery_fee == 5000 && $updatedTxn->tax_amount > 0) {
        echo "PASS: Fees persisted.\n";
    } else {
        echo "FAIL: Fees were reset!\n";
    }
} else {
    echo "Payment Failed: " . $data['message'] . "\n";
}
