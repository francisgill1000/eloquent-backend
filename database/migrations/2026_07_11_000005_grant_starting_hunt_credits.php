<?php

use App\Models\Shop;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time promo: every EXISTING shop that already has the Business Hunt (`leads`)
 * module gets a flat 100-credit opening balance, so the switch from the old
 * monthly search quota to the credit meter doesn't strand anyone at zero. Writes
 * a matching ledger row. Idempotent per shop via the 'initial_promo' marker.
 */
return new class extends Migration
{
    private const PROMO = 100;

    public function up(): void
    {
        Shop::query()->chunkById(200, function ($shops) {
            foreach ($shops as $shop) {
                if (! in_array('leads', $shop->modules ?? [], true)) {
                    continue;
                }

                $already = DB::table('hunt_credit_transactions')
                    ->where('shop_id', $shop->id)
                    ->where('reason', 'grant')
                    ->where('meta->note', 'initial_promo')
                    ->exists();
                if ($already) {
                    continue;
                }

                $new = (int) ($shop->hunt_credits ?? 0) + self::PROMO;
                DB::table('shops')->where('id', $shop->id)->update(['hunt_credits' => $new]);
                DB::table('hunt_credit_transactions')->insert([
                    'shop_id' => $shop->id,
                    'amount' => self::PROMO,
                    'reason' => 'grant',
                    'balance_after' => $new,
                    'meta' => json_encode(['note' => 'initial_promo']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Reverse only the promo grants; leave any later manual grants/spend alone.
        $rows = DB::table('hunt_credit_transactions')
            ->where('reason', 'grant')
            ->where('meta->note', 'initial_promo')
            ->get();

        foreach ($rows as $row) {
            DB::table('shops')->where('id', $row->shop_id)
                ->update(['hunt_credits' => DB::raw('GREATEST(hunt_credits - ' . (int) $row->amount . ', 0)')]);
        }

        DB::table('hunt_credit_transactions')
            ->where('reason', 'grant')
            ->where('meta->note', 'initial_promo')
            ->delete();
    }
};
