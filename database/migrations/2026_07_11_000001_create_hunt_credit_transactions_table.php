<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business Hunt credit ledger. One append-only row per credit movement (grant,
 * purchase, per-search debit, refund, manual adjustment). This is the audit
 * trail; the fast balance lives on shops.hunt_credits and is kept in sync inside
 * the same locked transaction (see HuntCreditService). Fully independent of the
 * Business Lens subscription meter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hunt_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');            // signed: +grant/+purchase/+refund, -search/-adjustment
            $table->string('reason', 32);          // grant|purchase|search|refund|adjustment
            $table->integer('balance_after');      // shop balance immediately after this row
            $table->json('meta')->nullable();      // e.g. {query, area} for a search, {note} for a grant
            $table->timestamps();
            $table->index(['shop_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hunt_credit_transactions');
    }
};
