<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('staff_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->index();
            $table->unsignedBigInteger('staff_id')->index();
            $table->unsignedTinyInteger('day_of_week'); // 0 = Sunday .. 6 = Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();
            $table->unique(['staff_id', 'day_of_week']);
        });

        Schema::create('staff_time_off', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->index();
            $table->unsignedBigInteger('staff_id')->index();
            $table->date('date');
            $table->time('start_time')->nullable(); // null = full-day off
            $table->time('end_time')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['staff_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_time_off');
        Schema::dropIfExists('staff_schedules');
    }
};
