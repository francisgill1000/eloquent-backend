<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('deal_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->default(0);
            $table->foreignId('user_id')->default(0);
            $table->date('follow_up_date')->nullable();
            $table->text('note')->nullable();
            $table->string('contact_method')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deal_activities');
    }
};
