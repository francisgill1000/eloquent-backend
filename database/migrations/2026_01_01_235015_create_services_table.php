<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('icon');
            $table->string('icon_bg')->nullable();
            $table->string('icon_color')->nullable();
            $table->string('title');
            $table->string('description');
            $table->decimal('rating', 2, 1);
            $table->decimal('price', 8, 2);
            $table->string('price_unit')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
