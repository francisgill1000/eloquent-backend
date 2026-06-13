<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The master bot-prompt test switch was removed (personas are managed per
 * shop now); this drops the orphaned table. Reverse recreates the bare
 * structure only — the preset texts are gone for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('bot_prompts');
    }

    public function down(): void
    {
        Schema::create('bot_prompts', function ($table) {
            $table->id();
            $table->string('name', 80);
            $table->text('body')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }
};
