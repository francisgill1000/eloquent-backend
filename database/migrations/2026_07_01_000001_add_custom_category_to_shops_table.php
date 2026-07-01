<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A shop that picks "Other" at registration (category_id = 0, outside the
     * fixed ServiceCategories list) stores its own free-text category name here.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('custom_category')->nullable()->after('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('custom_category');
        });
    }
};
