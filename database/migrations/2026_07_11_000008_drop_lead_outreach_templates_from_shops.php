<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Drops the per-shop lead outreach template overrides. The "Lead messages"
// editor that wrote them was removed; the per-lead WhatsApp drafts now use the
// packaged Lead::DEFAULT_OPENING / DEFAULT_FOLLOWUP templates only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['lead_opening_template', 'lead_followup_template']);
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->text('lead_opening_template')->nullable()->after('persona');
            $table->text('lead_followup_template')->nullable()->after('lead_opening_template');
        });
    }
};
