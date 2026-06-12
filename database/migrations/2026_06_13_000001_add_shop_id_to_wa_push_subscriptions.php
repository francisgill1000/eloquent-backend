<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Push notifications become per-shop: each subscription belongs to the shop
 * whose browser registered it, and a new message only notifies that shop.
 * Existing rows predate the column and were all the master's browsers —
 * backfill them to the master shop so Francis keeps his notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_push_subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable()->after('id');
            $table->index('shop_id');
        });

        $masterId = DB::table('shops')->where('is_master', true)->value('id');
        if ($masterId) {
            DB::table('wa_push_subscriptions')->whereNull('shop_id')->update(['shop_id' => $masterId]);
        }
    }

    public function down(): void
    {
        Schema::table('wa_push_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['shop_id']);
            $table->dropColumn('shop_id');
        });
    }
};
