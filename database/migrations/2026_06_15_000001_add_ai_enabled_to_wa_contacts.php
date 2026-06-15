<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent takeover lock: when a human staff member replies in a thread the AI
 * concierge auto-pauses for that conversation (ai_enabled = false) and only
 * resumes when staff flip it back on. Defaults true so every existing and new
 * thread keeps auto-replying until a human steps in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_contacts', function (Blueprint $table) {
            $table->boolean('ai_enabled')->default(true)->after('unread_count');
        });
    }

    public function down(): void
    {
        Schema::table('wa_contacts', function (Blueprint $table) {
            $table->dropColumn('ai_enabled');
        });
    }
};
