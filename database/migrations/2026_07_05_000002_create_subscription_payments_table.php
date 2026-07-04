<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 16);                 // monthly|annual
            $table->integer('amount_fils');
            $table->string('ziina_intent_id')->nullable()->index();
            $table->uuid('ziina_operation_id');
            $table->string('status', 16)->default('pending'); // pending|paid|failed
            $table->integer('period_days');             // 30 or 365
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
