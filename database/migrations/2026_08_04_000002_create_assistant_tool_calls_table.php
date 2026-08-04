<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            // Nullable: a turn's tools run before the conversation is created on
            // a brand-new thread. The controller backfills it afterwards.
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('shop_user_id')->nullable();
            $table->string('tool');
            $table->json('input');
            $table->json('result');
            $table->string('outcome', 16);
            $table->boolean('user_confirmed')->default(false);
            $table->unsignedInteger('duration_ms')->default(0);
            // created_at only — a log row is written once and never updated.
            $table->timestamp('created_at')->nullable();
            $table->index(['shop_id', 'created_at']);
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_tool_calls');
    }
};
