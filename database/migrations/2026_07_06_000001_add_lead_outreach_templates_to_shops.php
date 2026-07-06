<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->text('lead_opening_template')->nullable()->after('persona');
            $table->text('lead_followup_template')->nullable()->after('lead_opening_template');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['lead_opening_template', 'lead_followup_template']);
        });
    }
};
