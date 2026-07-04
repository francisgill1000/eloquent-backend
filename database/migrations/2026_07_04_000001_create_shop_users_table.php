<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shop_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('login_pin');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // A PIN identifies exactly one user within a shop (login lookup).
            $table->unique(['shop_id', 'login_pin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_users');
    }
};
