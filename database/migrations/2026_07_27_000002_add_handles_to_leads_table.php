<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Canonical absolute URLs (SocialHandle::normalize), so generously
            // sized — Facebook page URLs are long.
            foreach (['instagram', 'facebook', 'tiktok', 'linkedin'] as $column) {
                $table->string($column, 2048)->nullable();
            }
            $table->string('email', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['instagram', 'facebook', 'tiktok', 'linkedin', 'email']);
        });
    }
};
