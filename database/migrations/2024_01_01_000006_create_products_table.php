<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained()->onDelete('set null');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 15, 2);
            $table->integer('stock')->default(0);
            $table->string('barcode', 100)->nullable();
            $table->string('image')->nullable(); // Path to media storage
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'barcode']);
            $table->index('store_id');
            $table->index('category_id');
            $table->index(['store_id', 'barcode']);
            $table->index(['store_id', 'is_active']);
            $table->index(['store_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
