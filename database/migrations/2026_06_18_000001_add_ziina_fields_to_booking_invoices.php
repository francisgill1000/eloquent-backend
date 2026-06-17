<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_invoices', function (Blueprint $table) {
            // Ziina payment intent id — links an incoming webhook back to this invoice.
            $table->string('ziina_intent_id')->nullable()->index()->after('status');
            // Stable idempotency key for the create-intent call (one per invoice).
            $table->uuid('ziina_operation_id')->nullable()->after('ziina_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('booking_invoices', function (Blueprint $table) {
            $table->dropColumn(['ziina_intent_id', 'ziina_operation_id']);
        });
    }
};
