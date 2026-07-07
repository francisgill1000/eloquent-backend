<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('waitlist_notify_enabled')->default(true);
            $table->text('waitlist_notify_template')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn([
            'waitlist_notify_enabled', 'waitlist_notify_template',
        ]));
    }
};
