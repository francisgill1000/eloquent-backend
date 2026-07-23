<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_users', function (Blueprint $table) {
            // login_pin is dormant going forward — staff now log in with
            // email+password (see 2026-07-23-staff-login-design.md).
            $table->string('login_pin')->nullable()->change();
            $table->string('email')->nullable()->unique()->after('login_pin');
            $table->string('password')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('shop_users', function (Blueprint $table) {
            $table->dropColumn(['email', 'password']);
            $table->string('login_pin')->nullable(false)->change();
        });
    }
};
