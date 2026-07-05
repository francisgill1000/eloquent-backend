<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->string('category')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('source')->default('google_places'); // google_places|explorium|manual
            $table->string('external_ref')->nullable();          // place_id / provider id
            $table->string('status')->default('new');            // new|sent|replied|demo|won|pass
            $table->text('notes')->nullable();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('next_followup_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            // Dedupe: a shop can only hold one lead per external business.
            $table->unique(['shop_id', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
