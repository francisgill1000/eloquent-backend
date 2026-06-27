<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Local-dev only: wipes all shop-scoped data and seeds a SINGLE laundry shop
 * so the local sqlite DB mirrors a one-shop laundry setup.
 *
 *   php artisan db:seed --class=LocalLaundryShopSeeder
 *
 * Login (admin app):  shop_code 1001  /  pin 1234
 */
class LocalLaundryShopSeeder extends Seeder
{
    public function run(): void
    {
        // The "Laundry" category (id 11) lives in App\Support\ServiceCategories.

        // 1. Wipe shop-scoped data (local dev only).
        $wipe = [
            'catalogs', 'shop_working_hours', 'shop_login_activities',
            'booking_invoices', 'bookings', 'shop_customers', 'shops',
        ];
        Schema::disableForeignKeyConstraints();
        foreach ($wipe as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }
        // reset autoincrement on sqlite
        DB::table('sqlite_sequence')->whereIn('name', $wipe)->delete();
        Schema::enableForeignKeyConstraints();

        // 3. The one laundry shop.
        $shopId = DB::table('shops')->insertGetId([
            'name'                  => 'FreshPress Laundry & Dry Cleaning',
            'shop_code'             => '1001',
            'pin'                   => '1234',
            'logo'                  => 'https://images.unsplash.com/photo-1545173168-9f1947eebb7f',
            'hero_image'            => 'https://images.unsplash.com/photo-1582735689369-4fe89db7114c',
            'lat'                   => 25.2048,
            'lon'                   => 55.2708,
            'location'              => 'Jumeirah, Dubai',
            'phone'                 => '+971501234567',
            'is_verified'           => true,
            'category_id'           => 11, // Laundry (App\Support\ServiceCategories)
            'category_confirmed_at' => now(),
            'status'                => 'active',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        // 4. Working hours — open every day, 08:00–22:00, 30-min slots.
        for ($day = 0; $day <= 6; $day++) {
            DB::table('shop_working_hours')->insert([
                'shop_id'       => $shopId,
                'day_of_week'   => $day,
                'start_time'    => '08:00:00',
                'end_time'      => '22:00:00',
                'slot_duration' => 30,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // 5. Service menu (catalog) in AED.
        $menu = [
            ['Wash & Fold (per kg)',          'Everyday laundry washed, dried and neatly folded.',          12],
            ['Dry Cleaning — Shirt',          'Professional dry cleaning, pressed and hung.',               8],
            ['Dry Cleaning — 2-Piece Suit',   'Jacket and trousers dry cleaned and finished.',              35],
            ['Ironing / Pressing (per piece)','Crisp pressing for any single garment.',                     4],
            ['Bedding & Duvet Wash',          'Deep wash for duvets, sheets and pillow covers.',            45],
            ['Express Same-Day Service',      'Drop before 10am, ready by 6pm. Surcharge per order.',       25],
        ];
        foreach ($menu as [$title, $desc, $price]) {
            DB::table('catalogs')->insert([
                'title'              => $title,
                'description'        => $desc,
                'price'              => $price,
                'image'             => null,
                'shop_id'            => $shopId,
                'parent_category_id' => null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }

        $this->command->info("Seeded laundry shop #{$shopId} — login shop_code 1001 / pin 1234");
    }
}
