<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Rezzy sales line gets its own wa_accounts row so webhook traffic is
     * stored uniformly, but it belongs to no shop — shop_id must be nullable.
     */
    public function up(): void
    {
        Schema::table('wa_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wa_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable(false)->change();
        });
    }
};
