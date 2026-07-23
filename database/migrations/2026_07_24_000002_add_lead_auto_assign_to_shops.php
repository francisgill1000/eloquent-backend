<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shop round-robin state. Off by default — a shop opts in. The cursor holds
 * the shop_users.id that last received an auto-assigned lead so rotation
 * survives across requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('lead_auto_assign')->default(false);
            $table->unsignedBigInteger('lead_assign_cursor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['lead_auto_assign', 'lead_assign_cursor']);
        });
    }
};
