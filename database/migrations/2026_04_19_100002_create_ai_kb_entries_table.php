<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_kb_entries', function (Blueprint $table) {
            $table->id();
            $table->string('kb_id', 64)->unique();
            $table->json('patterns');            // array of regex strings
            $table->text('answer');
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedInteger('hit_count')->default(0);
            $table->string('source', 32)->default('seeded')->index(); // 'seeded' | 'suggested' | 'manual'
            $table->unsignedSmallInteger('priority')->default(100);    // lower = higher priority
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_kb_entries');
    }
};
