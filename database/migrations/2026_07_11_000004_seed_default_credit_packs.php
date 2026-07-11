<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed the three launch packs. Prices are master-editable afterwards (no deploy);
 * these are just the starting values. Idempotent: skips any pack name already
 * present so re-running never duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        $packs = [
            ['name' => 'Starter',  'credits' => 200,  'price_fils' => 19900, 'sort' => 1],
            ['name' => 'Growth',   'credits' => 500,  'price_fils' => 44900, 'sort' => 2],
            ['name' => 'Pro',      'credits' => 1000, 'price_fils' => 79900, 'sort' => 3],
        ];

        foreach ($packs as $pack) {
            $exists = DB::table('credit_packs')->where('name', $pack['name'])->exists();
            if ($exists) {
                continue;
            }
            DB::table('credit_packs')->insert($pack + [
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('credit_packs')->whereIn('name', ['Starter', 'Growth', 'Pro'])->delete();
    }
};
