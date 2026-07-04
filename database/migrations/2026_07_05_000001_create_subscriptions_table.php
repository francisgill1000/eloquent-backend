<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('trialing'); // trialing|active|expired
            $table->string('plan', 16)->nullable();             // monthly|annual|null
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('access_until')->nullable();       // authoritative expiry
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
