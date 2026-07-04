<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // Which ShopUser is acting on this token. Null = legacy/owner-equivalent.
            $table->unsignedBigInteger('shop_user_id')->nullable()->after('tokenable_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('shop_user_id');
        });
    }
};
