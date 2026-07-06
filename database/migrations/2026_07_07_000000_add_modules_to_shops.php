<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->json('modules')->nullable()->after('is_master');
        });

        // Backfill every existing shop to the bookings module.
        DB::table('shops')->update(['modules' => json_encode(['bookings'])]);
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('modules'));
    }
};
