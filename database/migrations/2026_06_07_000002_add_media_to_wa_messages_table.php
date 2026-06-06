<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_messages', function (Blueprint $table) {
            $table->string('media_id')->nullable()->after('wa_message_id');   // Meta media id
            $table->string('media_mime', 100)->nullable()->after('media_id');
            $table->string('media_path')->nullable()->after('media_mime');    // public disk path
        });
    }

    public function down(): void
    {
        Schema::table('wa_messages', function (Blueprint $table) {
            $table->dropColumn(['media_id', 'media_mime', 'media_path']);
        });
    }
};
