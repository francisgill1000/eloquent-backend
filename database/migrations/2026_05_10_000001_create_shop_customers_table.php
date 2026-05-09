<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('whatsapp', 32);
            $table->string('whatsapp_normalized', 24);
            $table->timestamps();

            $table->unique(['shop_id', 'whatsapp_normalized']);
            $table->index(['shop_id', 'whatsapp_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_customers');
    }
};
