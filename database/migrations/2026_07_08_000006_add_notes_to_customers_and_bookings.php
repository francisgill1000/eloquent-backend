<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shop_customers', function (Blueprint $table) {
            $table->text('notes')->nullable();
            $table->json('preferences')->nullable();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('services');
        });
    }

    public function down(): void
    {
        Schema::table('shop_customers', fn (Blueprint $table) => $table->dropColumn(['notes', 'preferences']));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('notes'));
    }
};
