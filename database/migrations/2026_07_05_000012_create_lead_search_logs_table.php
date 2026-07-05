<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing counter + usage log. One row per LIVE (billable) source call —
     * cache hits write nothing and consume no allowance. Monthly usage is
     * count(where shop_id AND created_at >= startOfMonth).
     */
    public function up(): void
    {
        Schema::create('lead_search_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('query');
            $table->string('area')->nullable();
            $table->unsignedInteger('results_count')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_search_logs');
    }
};
