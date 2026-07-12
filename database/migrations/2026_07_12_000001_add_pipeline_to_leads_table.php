<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // The named pipeline/list a lead was saved into (e.g. "digital media
            // pipeline"). Grouped implicitly alongside status; set at save time.
            $table->string('pipeline', 120)->nullable()->after('category');
            $table->index(['shop_id', 'pipeline']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'pipeline']);
            $table->dropColumn('pipeline');
        });
    }
};
