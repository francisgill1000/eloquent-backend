<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShopWorkingHoursSeeder extends Seeder
{
    public function run(): void
    {
        $shopId = 1;

        // Monday (1) to Saturday (6)
        for ($day = 1; $day <= 6; $day++) {
            DB::table('shop_working_hours')->updateOrInsert(
                [
                    'shop_id' => $shopId,
                    'day_of_week' => $day,
                ],
                [
                    'start_time' => '09:00:00',
                    'end_time' => '23:00:00',
                    'slot_duration' => 30,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}
