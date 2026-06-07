<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('body')->nullable();        // null on the default = "use the bot's own sales prompt"
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        // Seed the default. It represents the normal sales-bot behaviour; the
        // bot keeps its own local sales prompt, so the body stays null here and
        // the default can never be deleted — only overridden.
        DB::table('bot_prompts')->insert([
            'name' => 'Sales Bot',
            'body' => null,
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_prompts');
    }
};
