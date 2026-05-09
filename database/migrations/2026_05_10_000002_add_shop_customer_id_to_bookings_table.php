<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('shop_customer_id')
                ->nullable()
                ->after('shop_id')
                ->constrained('shop_customers')
                ->nullOnDelete();

            $table->index(['shop_id', 'shop_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'shop_customer_id']);
            $table->dropConstrainedForeignId('shop_customer_id');
        });
    }
};
