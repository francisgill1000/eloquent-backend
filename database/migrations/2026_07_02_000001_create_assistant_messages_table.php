<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('role', 10);            // user | assistant
            $table->text('content');
            $table->string('audio_path')->nullable();
            $table->string('audio_mime', 40)->nullable();
            $table->timestamps();
            $table->index(['shop_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
