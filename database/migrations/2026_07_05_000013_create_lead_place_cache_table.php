<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GLOBAL public-data cache — NOT tenant data. Business info from the source
     * is public and identical for everyone, so it is shared across shops like a
     * CDN to avoid re-billing Google for the same place. Tenant-owned copies
     * live in `leads` (created at save time).
     */
    public function up(): void
    {
        Schema::create('lead_place_cache', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('google_places');
            $table->string('external_ref')->unique();   // place_id
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->string('category')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_place_cache');
    }
};
