<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_working_hours', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('shop_id')->default(1);

            // 0 = Sunday, 6 = Saturday
            $table->tinyInteger('day_of_week');

            // Default working hours
            $table->time('start_time')->default('09:00:00');
            $table->time('end_time')->default('23:00:00');

            $table->integer('slot_duration')->default(30); // minutes

            $table->timestamps();

            $table->unique(['shop_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_working_hours');
    }
};
