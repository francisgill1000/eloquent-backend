<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the Rooms & Resources feature. Guarded so it is safe on every
 * environment: a no-op where the schema was never created (prod), and a clean
 * removal where it was (staging / any DB that ran the original migration).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('bookings', 'resource_id')) {
            Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('resource_id'));
        }

        if (Schema::hasColumn('catalogs', 'requires_resource_type')) {
            Schema::table('catalogs', fn (Blueprint $table) => $table->dropColumn('requires_resource_type'));
        }

        Schema::dropIfExists('resources');
    }

    public function down(): void
    {
        // The feature was removed intentionally; nothing to restore.
    }
};
