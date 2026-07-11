<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cached Business Hunt credit balance per shop. Never expires. Decremented on a
 * live lead search, topped up by a master grant or (later) a pack purchase.
 * Authoritative ledger is hunt_credit_transactions; this column is the O(1) read
 * kept in sync transactionally. Independent of the Lens subscription.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->integer('hunt_credits')->default(0)->after('lead_search_allowance');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('hunt_credits');
        });
    }
};
