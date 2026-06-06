<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Once set, the shop's category is locked. Old shops (registered when
        // the form hardcoded category_id=1) have NULL and must pick once.
        Schema::table('shops', function (Blueprint $table) {
            $table->timestamp('category_confirmed_at')->nullable()->after('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('category_confirmed_at');
        });
    }
};
