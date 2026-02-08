<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('guest_favourites', function (Blueprint $table) {
            $table->id();
            $table->string('device_id');
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['device_id', 'shop_id']); // prevent duplicates
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_favourites');
    }
};
