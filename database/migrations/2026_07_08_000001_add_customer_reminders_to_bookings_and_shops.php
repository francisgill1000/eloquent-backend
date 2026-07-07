<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Customer-facing WhatsApp reminder (distinct from the staff-facing
            // manual reminder tracked by reminder_sent_at).
            $table->dateTime('reminder_customer_sent_at')->nullable()->after('reminder_sent_at');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('booking_reminders_enabled')->default(true);
            $table->text('booking_reminder_template')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('reminder_customer_sent_at'));
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn(['booking_reminders_enabled', 'booking_reminder_template']));
    }
};
