<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shop switch: may this shop buy Business Hunt credit packs self-serve?
 * Default FALSE — self-serve purchase is SIMULATED (no real payment yet), so it
 * stays off in prod and the master enables it only for pilot/demo accounts. The
 * master (owner) account is always allowed regardless of this flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('hunt_self_serve')->default(false)->after('hunt_credits');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('hunt_self_serve');
        });
    }
};
