<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a period_type to ai_summaries so a shop can hold rolling30/week/month/
 * custom summaries side by side, and re-keys uniqueness on
 * (shop_id, period_type, period_from, period_to). Existing rows backfill to
 * 'rolling30' (the only kind that existed before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_summaries', function (Blueprint $table) {
            $table->string('period_type')->default('rolling30')->after('shop_id');
        });

        Schema::table('ai_summaries', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'summary_date']);
            $table->unique(['shop_id', 'period_type', 'period_from', 'period_to']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_summaries', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'period_type', 'period_from', 'period_to']);
            $table->unique(['shop_id', 'summary_date']);
        });

        Schema::table('ai_summaries', function (Blueprint $table) {
            $table->dropColumn('period_type');
        });
    }
};
