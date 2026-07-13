<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Deal value captured when a lead is won. deal_amount is the MONTHLY
            // price for a recurring deal, or the whole amount for a one-off.
            $table->decimal('deal_amount', 10, 2)->nullable()->after('status');
            $table->string('deal_type')->nullable()->after('deal_amount');           // one_off|recurring
            $table->unsignedSmallInteger('deal_term_months')->nullable()->after('deal_type'); // 1|3|6|12 (recurring only)
            $table->timestamp('deal_won_at')->nullable()->after('deal_term_months');  // period attribution
            $table->index(['shop_id', 'status', 'deal_won_at']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'status', 'deal_won_at']);
            $table->dropColumn(['deal_amount', 'deal_type', 'deal_term_months', 'deal_won_at']);
        });
    }
};
