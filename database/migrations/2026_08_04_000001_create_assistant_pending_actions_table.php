<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_pending_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            // Nullable: the controller creates the conversation lazily AFTER a
            // successful reply, so a first-turn preview has no thread yet.
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('tool');
            $table->json('input');
            $table->string('summary');
            $table->json('changes');
            $table->boolean('destructive')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['shop_id', 'tool', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_pending_actions');
    }
};
