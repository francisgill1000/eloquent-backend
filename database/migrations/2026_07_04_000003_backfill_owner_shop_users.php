<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Backfills Owner role + user for every existing shop. Delegates to the
 * idempotent rbac:backfill-owners command so it can also be re-run manually.
 */
return new class extends Migration {
    public function up(): void
    {
        Artisan::call('rbac:backfill-owners');
    }

    public function down(): void
    {
        // Leave backfilled owner data in place on rollback.
    }
};
