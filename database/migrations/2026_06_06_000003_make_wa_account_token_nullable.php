<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Token is now optional per account: most tenants' numbers live under
        // our own WABA and use the shared WHATSAPP_DEFAULT_TOKEN env token.
        Schema::table('wa_accounts', function (Blueprint $table) {
            $table->text('token')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wa_accounts', function (Blueprint $table) {
            $table->text('token')->nullable(false)->change();
        });
    }
};
