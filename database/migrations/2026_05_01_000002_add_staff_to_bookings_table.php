<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('staff_id')->nullable()->after('shop_id');
            $table->index('staff_id');
        });

        // Drop the old per-shop unique slot index. The exact constraint name
        // Laravel generated is `bookings_shop_id_date_start_time_unique`.
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('bookings_shop_id_date_start_time_unique');
        });

        // Add the new per-staff unique slot index. NULL staff_id (queued)
        // is treated as distinct, so multiple queued bookings on the same
        // (date, start_time) are allowed.
        Schema::table('bookings', function (Blueprint $table) {
            $table->unique(['staff_id', 'date', 'start_time'], 'bookings_staff_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropUnique('bookings_staff_slot_unique');
            $table->dropIndex(['staff_id']);
            $table->dropColumn('staff_id');
            $table->unique(['shop_id', 'date', 'start_time']);
        });
    }
};
