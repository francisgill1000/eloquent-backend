<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('catalogs', function (Blueprint $table) {
            // Per-service appointment length + post-service cleanup/turnaround time.
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('price');
            $table->unsignedSmallInteger('buffer_minutes')->default(0)->after('duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('catalogs', fn (Blueprint $table) => $table->dropColumn(['duration_minutes', 'buffer_minutes']));
    }
};
