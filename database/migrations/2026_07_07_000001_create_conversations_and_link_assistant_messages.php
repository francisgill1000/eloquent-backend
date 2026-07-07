<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('title');
            $table->timestamps();
            $table->index(['shop_id', 'updated_at']);
        });

        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->nullable()->after('shop_id');
        });

        // Backfill: bundle each shop's existing messages into one "Previous chat".
        $shopIds = DB::table('assistant_messages')->distinct()->pluck('shop_id');
        foreach ($shopIds as $shopId) {
            $range = DB::table('assistant_messages')
                ->where('shop_id', $shopId)
                ->selectRaw('MIN(created_at) as mn, MAX(created_at) as mx')
                ->first();

            $conversationId = DB::table('conversations')->insertGetId([
                'shop_id' => $shopId,
                'title' => 'Previous chat',
                'created_at' => $range->mn ?? now(),
                'updated_at' => $range->mx ?? now(),
            ]);

            DB::table('assistant_messages')
                ->where('shop_id', $shopId)
                ->update(['conversation_id' => $conversationId]);
        }

        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'id']);
            $table->dropColumn('conversation_id');
        });
        Schema::dropIfExists('conversations');
    }
};
