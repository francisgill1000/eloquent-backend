<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguish who actually sent each message: customer | ai | staff. Inbound
 * is always the customer; outbound is the AI unless a human staff member sent
 * it, in which case the agent-takeover lock pauses the AI for that thread.
 *
 * Backfill is best-effort: 'in' rows are the customer, 'out' rows the AI.
 * Historical 'out' rows cannot be disambiguated — past human staff replies are
 * indistinguishable from AI replies and so are all backfilled as 'ai'. Going
 * forward, recordMessage() stamps the real sender_type, so only genuine staff
 * replies trigger the auto-pause.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_messages', function (Blueprint $table) {
            $table->string('sender_type', 16)->nullable()->after('direction'); // customer|ai|staff
        });

        DB::table('wa_messages')->where('direction', 'in')->update(['sender_type' => 'customer']);
        DB::table('wa_messages')->where('direction', 'out')->update(['sender_type' => 'ai']);
    }

    public function down(): void
    {
        Schema::table('wa_messages', function (Blueprint $table) {
            $table->dropColumn('sender_type');
        });
    }
};
