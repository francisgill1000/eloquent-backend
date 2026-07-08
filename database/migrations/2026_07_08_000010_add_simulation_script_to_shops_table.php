<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive + reversible: stores the per-shop demo-simulation script (turns,
// booking preview fields, voices, pacing). Null = use the generated default.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->json('simulation_script')->nullable()->after('persona');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('simulation_script');
        });
    }
};
