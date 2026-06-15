<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead triage: staff can tag each conversation so they know who to chase.
 * Values: hot | warm | cold | follow_up | not_interested. NULL = "New" (unset)
 * — the default for every existing and new thread.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_contacts', function (Blueprint $table) {
            $table->string('lead_status', 20)->nullable()->after('ai_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('wa_contacts', function (Blueprint $table) {
            $table->dropColumn('lead_status');
        });
    }
};
