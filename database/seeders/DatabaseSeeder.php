<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Store;
use App\Models\Role;
use App\Models\Category;
use App\Models\Product;
use App\Models\Customer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // ==========================================
        // 1. CREATE ROLES
        // ==========================================
        $roles = [
            ['id' => 1, 'name' => 'super_admin', 'guard_name' => 'web'],
            ['id' => 2, 'name' => 'admin', 'guard_name' => 'web'],
            ['id' => 3, 'name' => 'manager', 'guard_name' => 'web'],
            ['id' => 4, 'name' => 'cashier', 'guard_name' => 'web'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['id' => $role['id']], $role);
        }

        $this->command->info('✅ Roles created');

        // ==========================================
        // 2. CREATE SUPER ADMIN USER
        // ==========================================
        $superAdmin = User::updateOrCreate(
            ['email' => 'superadmin@pos.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'is_super_admin' => \DB::raw('true'),
            ]
        );

        $this->command->info('✅ Super Admin created: superadmin@pos.com / password');

        // ==========================================
        // 3. CREATE DEMO STORE
        // ==========================================
        $store = Store::updateOrCreate(
            ['slug' => 'kopi-kenangan'],
            [
                'name' => 'Kopi Kenangan',
                'type' => 'cafe',
                'address' => 'Jl. Sudirman No. 123, Jakarta',
                'phone' => '021-1234567',
                'is_active' => \DB::raw('true'),
            ]
        );

        $this->command->info('✅ Demo store created: kopi-kenangan');

        // Set store attendance settings
        $store->setSetting('timezone', 'Asia/Jakarta');
        $store->setSetting('store_opening_time', '08:00');
        $store->setSetting('late_tolerance_minutes', '30');

        $this->command->info('✅ Store settings configured (timezone, opening time, late tolerance)');

        // ==========================================
        // 4. CREATE USERS WITH ROLES
        // ==========================================

        // Admin
        $admin = User::updateOrCreate(
            ['email' => 'admin@pos.com'],
            [
                'name' => 'Store Admin',
                'password' => Hash::make('password'),
            ]
        );
        \App\Models\UserStoreRole::updateOrCreate(
            ['user_id' => $admin->id, 'store_id' => $store->id],
            ['role_id' => 2]
        );

        // Manager
        $manager = User::updateOrCreate(
            ['email' => 'manager@pos.com'],
            [
                'name' => 'Store Manager',
                'password' => Hash::make('password'),
            ]
        );
        \App\Models\UserStoreRole::updateOrCreate(
            ['user_id' => $manager->id, 'store_id' => $store->id],
            ['role_id' => 3]
        );

        // Cashier
        $cashier = User::updateOrCreate(
            ['email' => 'cashier@pos.com'],
            [
                'name' => 'Cashier One',
                'password' => Hash::make('password'),
            ]
        );
        \App\Models\UserStoreRole::updateOrCreate(
            ['user_id' => $cashier->id, 'store_id' => $store->id],
            ['role_id' => 4]
        );

        $this->command->info('✅ Users created: admin@pos.com, manager@pos.com, cashier@pos.com');

        // ==========================================
        // 5. CREATE CATEGORIES
        // ==========================================
        $categories = [
            ['name' => 'Coffee', 'sort_order' => 1],
            ['name' => 'Non-Coffee', 'sort_order' => 2],
            ['name' => 'Food', 'sort_order' => 3],
        ];

        foreach ($categories as $cat) {
            Category::updateOrCreate(
                ['store_id' => $store->id, 'name' => $cat['name']],
                array_merge($cat, ['store_id' => $store->id])
            );
        }

        $this->command->info('✅ Categories created');

        // ==========================================
        // 6. CREATE PRODUCTS
        // ==========================================
        $coffeeCategory = Category::where('store_id', $store->id)->where('name', 'Coffee')->first();
        $nonCoffeeCategory = Category::where('store_id', $store->id)->where('name', 'Non-Coffee')->first();
        $foodCategory = Category::where('store_id', $store->id)->where('name', 'Food')->first();

        $products = [
            ['name' => 'Es Kopi Susu', 'price' => 25000, 'stock' => 100, 'category_id' => $coffeeCategory->id, 'barcode' => 'KOPI001'],
            ['name' => 'Es Americano', 'price' => 22000, 'stock' => 100, 'category_id' => $coffeeCategory->id, 'barcode' => 'KOPI002'],
            ['name' => 'Hot Latte', 'price' => 28000, 'stock' => 100, 'category_id' => $coffeeCategory->id, 'barcode' => 'KOPI003'],
            ['name' => 'Matcha Latte', 'price' => 30000, 'stock' => 50, 'category_id' => $nonCoffeeCategory->id, 'barcode' => 'NONCOF001'],
            ['name' => 'Es Coklat', 'price' => 25000, 'stock' => 50, 'category_id' => $nonCoffeeCategory->id, 'barcode' => 'NONCOF002'],
            ['name' => 'Croissant', 'price' => 35000, 'stock' => 20, 'category_id' => $foodCategory->id, 'barcode' => 'FOOD001'],
            ['name' => 'Roti Bakar', 'price' => 20000, 'stock' => 30, 'category_id' => $foodCategory->id, 'barcode' => 'FOOD002'],
        ];

        foreach ($products as $prod) {
            Product::updateOrCreate(
                ['store_id' => $store->id, 'barcode' => $prod['barcode']],
                array_merge($prod, ['store_id' => $store->id, 'is_active' => \DB::raw('true')])
            );
        }

        $this->command->info('✅ Products created');

        // ==========================================
        // 7. CREATE DEMO CUSTOMERS
        // ==========================================
        $customers = [
            ['name' => 'John Doe', 'phone' => '08123456789', 'points' => 500, 'barcode' => 'MBR001'],
            ['name' => 'Jane Smith', 'phone' => '08198765432', 'points' => 1000, 'barcode' => 'MBR002'],
            ['name' => 'Guest Customer', 'phone' => '00000000000', 'points' => 0, 'barcode' => null],
        ];

        foreach ($customers as $cust) {
            Customer::updateOrCreate(
                ['phone' => $cust['phone']],
                $cust
            );
        }

        $this->command->info('✅ Customers created');

        // ==========================================
        // SUMMARY
        // ==========================================
        $this->command->newLine();
        $this->command->info('🎉 Database seeding complete!');
        $this->command->newLine();
        $this->command->table(
            ['Role', 'Email', 'Password'],
            [
                ['Super Admin', 'superadmin@pos.com', 'password'],
                ['Admin', 'admin@pos.com', 'password'],
                ['Manager', 'manager@pos.com', 'password'],
                ['Cashier', 'cashier@pos.com', 'password'],
            ]
        );
    }
}
