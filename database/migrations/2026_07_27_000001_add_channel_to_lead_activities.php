<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_activities', function (Blueprint $table) {
            // Nullable rather than defaulted: a status change has no channel,
            // and a default would fabricate one.
            $table->string('channel', 20)->nullable();
            $table->string('direction', 3)->nullable();
            $table->index('channel');
        });

        // Every touch logged before this migration carried a payload channel
        // hardcoded to 'whatsapp'. This is not a guess — it is exactly what the
        // old code asserted. Other activity types have no channel and stay null.
        DB::table('lead_activities')
            ->where('type', 'contacted')
            ->update(['channel' => 'whatsapp', 'direction' => 'out']);
    }

    public function down(): void
    {
        Schema::table('lead_activities', function (Blueprint $table) {
            $table->dropIndex(['channel']);
            $table->dropColumn(['channel', 'direction']);
        });
    }
};
