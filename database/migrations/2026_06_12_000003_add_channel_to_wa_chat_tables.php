<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app "Live Chat" channel alongside WhatsApp. App-channel contacts belong
 * to a shop directly (no WaAccount) and are identified by the customer
 * device id that eloquent-bookings already sends on every request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_contacts', function (Blueprint $table) {
            $table->string('channel', 8)->default('wa')->after('id');
            $table->unsignedBigInteger('shop_id')->nullable()->after('channel');
            $table->string('device_id', 64)->nullable()->after('shop_id');
            $table->unsignedBigInteger('wa_account_id')->nullable()->change();
            $table->string('wa_number')->nullable()->change();
            $table->index(['shop_id', 'channel', 'last_message_at']);
            $table->unique(['shop_id', 'device_id']);
        });

        Schema::table('wa_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('wa_account_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wa_contacts', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'device_id']);
            $table->dropIndex(['shop_id', 'channel', 'last_message_at']);
            $table->dropColumn(['channel', 'shop_id', 'device_id']);
        });
    }
};
