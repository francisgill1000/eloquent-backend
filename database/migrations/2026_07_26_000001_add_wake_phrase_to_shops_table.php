<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive + reversible: the phrase an owner says on the AI Summary page to
// hear it read aloud. Null = fall back to the shop's own name (no hardcoded
// tenant identity).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('wake_phrase', 60)->nullable()->after('simulation_script');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('wake_phrase');
        });
    }
};
