<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $validShopIds = DB::table('shops')->pluck('id')->all();
        $validShopIdSet = array_flip($validShopIds);

        $rows = DB::table('bookings')
            ->whereNotNull('customer_whatsapp')
            ->where('customer_whatsapp', '!=', '')
            ->whereNull('shop_customer_id')
            ->whereIn('shop_id', $validShopIds)
            ->orderBy('id')
            ->get(['id', 'shop_id', 'customer_name', 'customer_whatsapp']);

        $cache = []; // [ "{shop_id}:{normalized}" => shop_customer_id ]

        foreach ($rows as $b) {
            // Defensive guard: even after the WHERE filter, skip if the shop
            // disappeared mid-run (FK would reject the insert otherwise).
            if (!isset($validShopIdSet[$b->shop_id])) continue;

            $normalized = preg_replace('/\D+/', '', (string) $b->customer_whatsapp);
            if ($normalized === '') continue;

            // Match on the last 9 digits to coalesce country-code variants.
            $tail = strlen($normalized) > 9 ? substr($normalized, -9) : $normalized;
            $cacheKey = $b->shop_id . ':' . $tail;

            if (isset($cache[$cacheKey])) {
                $customerId = $cache[$cacheKey];
            } else {
                $existing = DB::table('shop_customers')
                    ->where('shop_id', $b->shop_id)
                    ->where('whatsapp_normalized', 'LIKE', '%' . $tail)
                    ->first();

                if ($existing) {
                    $customerId = $existing->id;
                    if (empty($existing->name) && !empty($b->customer_name)) {
                        DB::table('shop_customers')
                            ->where('id', $customerId)
                            ->update(['name' => $b->customer_name, 'updated_at' => now()]);
                    }
                } else {
                    $customerId = DB::table('shop_customers')->insertGetId([
                        'shop_id'              => $b->shop_id,
                        'name'                 => $b->customer_name,
                        'whatsapp'             => $b->customer_whatsapp,
                        'whatsapp_normalized'  => $normalized,
                        'created_at'           => now(),
                        'updated_at'           => now(),
                    ]);
                }

                $cache[$cacheKey] = $customerId;
            }

            DB::table('bookings')->where('id', $b->id)->update(['shop_customer_id' => $customerId]);
        }
    }

    public function down(): void
    {
        DB::table('bookings')->update(['shop_customer_id' => null]);
        DB::table('shop_customers')->delete();
    }
};
