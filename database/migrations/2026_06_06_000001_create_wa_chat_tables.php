<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->unique();
            $table->string('phone_number')->nullable();      // display number, e.g. +9715xxxxxxx
            $table->string('phone_number_id')->unique();     // Meta Phone Number ID — webhook routing key
            $table->string('waba_id')->nullable();           // WhatsApp Business Account ID
            $table->text('token');                           // encrypted via model cast
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('wa_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wa_account_id');
            $table->string('wa_number');                     // customer's WhatsApp number (digits)
            $table->string('name')->nullable();              // WhatsApp profile name
            $table->string('last_message_preview', 500)->nullable();
            $table->string('last_message_direction', 3)->nullable(); // in|out
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();
            $table->unique(['wa_account_id', 'wa_number']);
            $table->index(['wa_account_id', 'last_message_at']);
        });

        Schema::create('wa_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wa_account_id');
            $table->unsignedBigInteger('wa_contact_id');
            $table->string('direction', 3);                  // in|out
            $table->string('type', 20)->default('text');     // text|voice|image|...
            $table->text('body');
            $table->string('wa_message_id')->nullable();     // Meta message id (dedupe)
            $table->string('status', 20)->nullable();        // sent|failed|...
            $table->timestamps();
            $table->index(['wa_contact_id', 'id']);
            $table->index('wa_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_messages');
        Schema::dropIfExists('wa_contacts');
        Schema::dropIfExists('wa_accounts');
    }
};
