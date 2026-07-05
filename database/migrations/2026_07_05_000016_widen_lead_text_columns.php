<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * website/address can exceed 255 chars (e.g. Google returns a long
     * search-redirect URL as the "website" when a place has no real site).
     * Widen them to TEXT so a single long value can't fail the whole search.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->text('website')->nullable()->change();
            $table->text('address')->nullable()->change();
        });
        Schema::table('lead_place_cache', function (Blueprint $table) {
            $table->text('website')->nullable()->change();
            $table->text('address')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('website')->nullable()->change();
            $table->string('address')->nullable()->change();
        });
        Schema::table('lead_place_cache', function (Blueprint $table) {
            $table->string('website')->nullable()->change();
            $table->string('address')->nullable()->change();
        });
    }
};
