<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // Per-shop WhatsApp assistant persona (system prompt). Null = use the
            // auto-generated category-based prompt (existing behavior).
            $table->text('persona')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('persona');
        });
    }
};
