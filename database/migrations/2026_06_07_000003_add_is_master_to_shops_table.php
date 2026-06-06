<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master accounts (owner only) can view all shops' data incl. code+PIN.
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('is_master')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('is_master');
        });
    }
};
