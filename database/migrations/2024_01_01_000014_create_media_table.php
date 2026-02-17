<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Media table for store-scoped image storage.
     * Each store has its own media folder accessible by all users in that store.
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->onDelete('cascade');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('filename'); // Original filename
            $table->string('path'); // Storage path (relative to store folder)
            $table->string('disk')->default('public'); // Storage disk
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0); // File size in bytes
            $table->string('folder')->nullable(); // Virtual folder for organization
            $table->timestamps();

            $table->index('store_id');
            $table->index(['store_id', 'folder']);
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
