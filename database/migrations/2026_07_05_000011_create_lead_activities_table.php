<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->string('type');                          // status_change|note|contacted
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('user_id')->nullable(); // acting shop_user id
            $table->timestamps();

            $table->index(['lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
