<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Business Hunt credit-pack purchase paid via Ziina. Mirrors
 * subscription_payments: a row is created 'pending' before redirecting to
 * Ziina's hosted page, then flipped 'paid' by the webhook, which grants the
 * credits. `credits`/`amount_fils` are snapshotted so later pack edits never
 * change what a completed order already granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pack_id')->nullable()->constrained('credit_packs')->nullOnDelete();
            $table->unsignedInteger('credits');       // snapshot at purchase time
            $table->unsignedInteger('amount_fils');   // snapshot at purchase time
            $table->string('ziina_intent_id')->nullable()->index();
            $table->uuid('ziina_operation_id');
            $table->string('status', 16)->default('pending'); // pending|paid|failed
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_purchases');
    }
};
