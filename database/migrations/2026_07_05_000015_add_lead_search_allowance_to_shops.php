<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-shop override of config('leads.monthly_search_allowance'). NULL means
     * "use the config default".
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedInteger('lead_search_allowance')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('lead_search_allowance');
        });
    }
};
