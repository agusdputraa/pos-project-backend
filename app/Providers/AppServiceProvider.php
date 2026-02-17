<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fix Neon PostgreSQL schema issue
        // Set search_path explicitly after connecting
        if (config('database.default') === 'pgsql') {
            try {
                DB::statement('SET search_path TO public');
            } catch (\Exception $e) {
                // Connection not yet available during migrations
            }
        }
    }
}
