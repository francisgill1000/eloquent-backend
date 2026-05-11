<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_login_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')
                ->constrained('shops')
                ->cascadeOnDelete();
            $table->string('login_method', 16)->default('pin'); // pin | qr | auto
            $table->string('ip_address', 45)->nullable();
            $table->string('device_id', 191)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('logged_in_at')->index();
            $table->timestamps();

            $table->index(['shop_id', 'logged_in_at']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('pin');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
        Schema::dropIfExists('shop_login_activities');
    }
};
