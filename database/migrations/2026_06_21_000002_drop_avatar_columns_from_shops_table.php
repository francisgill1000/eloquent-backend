<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Removes the LiveAvatar "Video Assistant" columns. Guarded so it is a no-op on
// fresh databases (where the add-columns migration was deleted) and cleans up
// databases that already ran it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            if (Schema::hasColumn('shops', 'avatar_id')) {
                $table->dropColumn('avatar_id');
            }
            if (Schema::hasColumn('shops', 'voice_id')) {
                $table->dropColumn('voice_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('avatar_id')->nullable();
            $table->string('voice_id')->nullable();
        });
    }
};
