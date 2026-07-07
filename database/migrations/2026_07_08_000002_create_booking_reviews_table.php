<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id')->index();
            $table->unsignedBigInteger('booking_id')->unique();
            $table->unsignedBigInteger('shop_customer_id')->nullable();
            $table->string('token', 64)->unique();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();
            $table->dateTime('review_request_sent_at')->nullable();
            $table->dateTime('rated_at')->nullable();
            $table->timestamps();
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('booking_reviews_enabled')->default(true);
            $table->string('google_review_url')->nullable();
            $table->text('review_request_template')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reviews');
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn([
            'booking_reviews_enabled', 'google_review_url', 'review_request_template',
        ]));
    }
};
