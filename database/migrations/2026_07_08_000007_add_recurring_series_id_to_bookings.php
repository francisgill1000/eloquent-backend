<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('recurring_series_id', 40)->nullable()->index()->after('booking_reference');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('recurring_series_id'));
    }
};
