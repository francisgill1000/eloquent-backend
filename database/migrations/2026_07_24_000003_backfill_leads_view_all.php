<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Seeds the new permissions, then hands every existing role that can view leads
 * the widened leads.view_all so the deploy is a no-op for live shops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('db:seed', ['--class' => \Database\Seeders\PermissionSeeder::class, '--force' => true]);
        Artisan::call('leads:backfill-view-all');
    }

    public function down(): void
    {
        // Leave the grants in place — removing them would hide leads.
    }
};
