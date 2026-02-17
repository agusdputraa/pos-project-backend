<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\StoreController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\VoucherController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\SearchController;

// ==========================================
// PUBLIC ROUTES
// ==========================================
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

// ==========================================
// PROTECTED ROUTES (Requires Authentication)
// ==========================================
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // My Stores (User's assigned stores)
    Route::get('/my-stores', [StoreController::class, 'myStores']);

    // User Preferences
    Route::get('/preferences', [SettingController::class, 'getUserPreferences']);
    Route::put('/preferences', [SettingController::class, 'updateUserPreferences']);

    // Customers (Global - not store-scoped)
    Route::get('/customers/search', [CustomerController::class, 'search']);
    Route::get('/customers/barcode/{barcode}', [CustomerController::class, 'findByBarcode']);
    // Customers (Global - Admin Only?)
    // Route::apiResource('customers', CustomerController::class);
    Route::post('/customers/{customer}/adjust-points', [CustomerController::class, 'adjustPoints']); // Admin & Manager

    // ==========================================
    // SUPER ADMIN ROUTES
    // ==========================================
    // Stores Management
    Route::get('/stores', [StoreController::class, 'index']);
    Route::post('/stores', [StoreController::class, 'store']);
    Route::put('/stores/{store}', [StoreController::class, 'update']);
    Route::delete('/stores/{store}', [StoreController::class, 'destroy']);

    // Users Management
    Route::apiResource('users', UserController::class);
    Route::post('/users/{user}/assign-role', [UserController::class, 'assignRole']);
    Route::post('/users/{user}/remove-role', [UserController::class, 'removeRole']);

    // Roles
    Route::get('/roles', [RoleController::class, 'index']);

    // ==========================================
    // STORE-SCOPED ROUTES (/{store-slug}/...)
    // ==========================================
    Route::prefix('{storeSlug}')->group(function () {

        // Global Search
        Route::get('/search', [SearchController::class, 'index']);

        // Store Info
        Route::get('/', [StoreController::class, 'show']);

        // Categories
        Route::apiResource('categories', CategoryController::class);

        // Products
        Route::get('/products/scan/{barcode}', [ProductController::class, 'findByBarcode']);
        Route::post('/products/import', [ProductController::class, 'bulkImport']);
        Route::apiResource('products', ProductController::class);
        Route::post('/products/{product}/stock', [ProductController::class, 'updateStock']);

        // Customers (Store Scoped Access)
        Route::get('/customers/scan/{barcode}', [CustomerController::class, 'findByBarcode']);
        Route::apiResource('customers', CustomerController::class);

        // Vouchers
        Route::apiResource('vouchers', VoucherController::class);
        Route::post('/vouchers/validate', [VoucherController::class, 'validateVoucher']);
        Route::get('/vouchers/barcode/{barcode}', [VoucherController::class, 'findByBarcode']);
        Route::post('/vouchers/redeem-points', [VoucherController::class, 'redeemPoints']);

        // Media Gallery (Store-scoped)
        Route::get('/media', [MediaController::class, 'index']);
        Route::post('/media', [MediaController::class, 'store']);
        Route::get('/media/{media}', [MediaController::class, 'show']);
        Route::put('/media/{media}', [MediaController::class, 'update']);
        Route::delete('/media/{media}', [MediaController::class, 'destroy']);
        Route::post('/media/folder', [MediaController::class, 'createFolder']);

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Transactions
        Route::get('/transactions/scan/{code}', [TransactionController::class, 'findByCode']);
        Route::get('/transactions/group-by-customer', [TransactionController::class, 'groupByCustomer']);
        Route::get('/transactions/group-by-product', [TransactionController::class, 'groupByProduct']);
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::post('/transactions', [TransactionController::class, 'store']);
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
        Route::put('/transactions/{transaction}', [TransactionController::class, 'update']);
        Route::post('/transactions/{transaction}/items', [TransactionController::class, 'addItem']);
        Route::delete('/transactions/{transaction}/items/{itemId}', [TransactionController::class, 'removeItem']);
        Route::get('/transactions/{transactionNumber}/snapshot/{type}', [TransactionController::class, 'getSnapshot']);
        Route::post('/transactions/{transaction}/pay', [TransactionController::class, 'pay']);
        Route::post('/transactions/{transaction}/cancel', [TransactionController::class, 'cancel']);
        Route::get('/transactions/{transaction}/receipt', [TransactionController::class, 'receipt']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/daily', [ReportController::class, 'dailySummary']);
            Route::get('/sales', [ReportController::class, 'salesReport']);
            Route::get('/inventory', [ReportController::class, 'inventoryAnalysis']); // New Endpoint
            Route::get('/top-products', [ReportController::class, 'topProducts']);
            Route::get('/top-customers', [ReportController::class, 'topCustomers']);
        });

        // Attendance (Cashier, Manager, Admin)
        Route::get('/attendance', [AttendanceController::class, 'index']);
        Route::get('/attendance/today', [AttendanceController::class, 'today']);
        Route::get('/attendance/active', [AttendanceController::class, 'activeStaff']); // New Endpoint
        Route::get('/attendance/{attendance}', [AttendanceController::class, 'show']);
        Route::post('/attendance/check-in', [AttendanceController::class, 'checkIn']);
        Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut']);

        // Store Settings (Admin only)
        Route::get('/settings', [SettingController::class, 'getStoreSettings']);
        Route::put('/settings', [SettingController::class, 'updateStoreSettings']);

        // Store Info Update (Admin/Manager)
        Route::put('/info', [StoreController::class, 'updateInfo']);

        // Store Users (Admin/Manager)
        Route::get('/admin/users', [UserController::class, 'storeUsers']);
    });
});

// ==========================================
// MEDIA PROXY (CORS FIX)
// ==========================================
Route::get('/media/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);
    if (!file_exists($fullPath)) {
        abort(404);
    }
    $mime = mime_content_type($fullPath);
    return response()->file($fullPath, [
        'Content-Type' => $mime,
        'Cache-Control' => 'public, max-age=31536000',
    ]);
})->where('path', '.*');
