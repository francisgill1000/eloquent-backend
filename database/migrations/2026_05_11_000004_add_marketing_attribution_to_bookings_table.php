<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedBigInteger('promo_code_id')->nullable()->after('charges');
            $table->unsignedBigInteger('marketing_campaign_id')->nullable()->after('promo_code_id');
            $table->decimal('discount_amount', 8, 2)->default(0)->after('marketing_campaign_id');
            $table->index('promo_code_id', 'bk_promo_idx');
            $table->index('marketing_campaign_id', 'bk_campaign_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bk_promo_idx');
            $table->dropIndex('bk_campaign_idx');
            $table->dropColumn(['promo_code_id', 'marketing_campaign_id', 'discount_amount']);
        });
    }
};
