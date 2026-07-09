<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // 'owner' = the shop's own Ask threads; 'customer' = public self-service
            // booking conversations, keyed by the visitor's device id.
            $table->string('source')->default('owner')->after('shop_id');
            $table->string('device_id')->nullable()->after('source');
            $table->index(['shop_id', 'source', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'source', 'device_id']);
            $table->dropColumn(['source', 'device_id']);
        });
    }
};
