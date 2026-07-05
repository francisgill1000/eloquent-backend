<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GLOBAL query -> results cache. Maps a normalized (query, area) to the set
     * of place refs it returned, so a repeat search is served from
     * lead_place_cache with no billable source call.
     */
    public function up(): void
    {
        Schema::create('lead_search_cache', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('google_places');
            $table->string('query_key')->unique(); // md5(lower(trim(query))|lower(trim(area)))
            $table->string('query');
            $table->string('area')->nullable();
            $table->json('external_refs'); // ordered list of place_ids
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_search_cache');
    }
};
