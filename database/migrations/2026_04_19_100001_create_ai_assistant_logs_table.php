<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_assistant_logs', function (Blueprint $table) {
            $table->id();
            $table->text('message');
            $table->string('source', 32)->index();           // 'knowledge_base' | 'llm'
            $table->string('kb_id', 64)->nullable()->index();
            $table->string('llm_action', 32)->nullable();    // 'chat' | 'find_shops' | 'needs_location'
            $table->boolean('matched')->default(false)->index();
            $table->string('user_id', 64)->nullable();
            $table->string('conversation_id', 36)->nullable()->index();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lon', 10, 7)->nullable();
            $table->text('reply')->nullable();
            $table->boolean('reviewed')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_logs');
    }
};
