<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead ownership. assigned_to_id nulls on user delete so a departing agent's
 * leads fall back into the unassigned pool rather than becoming orphans only
 * the database can see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('assigned_to_id')->nullable()
                ->constrained('shop_users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            $table->index(['shop_id', 'assigned_to_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'assigned_to_id', 'status']);
            $table->dropConstrainedForeignId('assigned_to_id');
            $table->dropColumn('assigned_at');
        });
    }
};
