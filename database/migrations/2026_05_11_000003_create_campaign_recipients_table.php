<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('marketing_campaign_id');
            $table->unsignedBigInteger('shop_customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_whatsapp')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('booking_id')->nullable();   // attributed booking, if any
            $table->timestamp('booked_at')->nullable();
            $table->timestamps();

            $table->index('marketing_campaign_id', 'cr_campaign_idx');
            $table->index('shop_customer_id', 'cr_customer_idx');
            $table->index('booking_id', 'cr_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};
