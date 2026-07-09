<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores one AI summary per shop per day so summaries are kept for later
 * reference. Content is derived from actual reporting metrics (never invented).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->index();
            $table->date('summary_date');          // the day it was generated
            $table->date('period_from');           // the performance window it covers
            $table->date('period_to');
            $table->text('summary');
            $table->json('patterns');
            $table->json('recommendations');
            $table->string('model')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'summary_date']); // one per shop per day (upsert)
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_summaries');
    }
};
