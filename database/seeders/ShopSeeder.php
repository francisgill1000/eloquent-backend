<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ShopSeeder extends Seeder
{
    public function run(): void
    {
        $shops = [
            [
                'name' => 'Precision Plumbing Co.',
                'logo' => 'https://images.unsplash.com/photo-1581578731548-c64695cc6952',
                'hero_image' => 'https://images.unsplash.com/photo-1581578731548-c64695cc6952',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => true,
                'category_id' => 1,
            ],
            [
                'name' => 'FlowMaster Repairs',
                'logo' => 'https://images.unsplash.com/photo-1581092580497-e0d23cbdf1dc',
                'hero_image' => 'https://images.unsplash.com/photo-1581092580497-e0d23cbdf1dc',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => false,
                'category_id' => 2,
            ],
            [
                'name' => 'Elite Pipe Solutions',
                'logo' => 'https://images.unsplash.com/photo-1621905252507-b35492cc74b4',
                'hero_image' => 'https://images.unsplash.com/photo-1621905252507-b35492cc74b4',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => true,
                'category_id' => 1,
            ],
            [
                'name' => 'Reliable Rooters',
                'logo' => 'https://images.unsplash.com/photo-1600566752355-35792bedcfea',
                'hero_image' => 'https://images.unsplash.com/photo-1600566752355-35792bedcfea',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => false,
                'category_id' => 2,
            ],
            [
                'name' => 'RapidFix Plumbing',
                'logo' => 'https://images.unsplash.com/photo-1584622650111-993a426fbf0a',
                'hero_image' => 'https://images.unsplash.com/photo-1584622650111-993a426fbf0a',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => true,
                'category_id' => 1,
            ],
            [
                'name' => 'AquaCare Services',
                'logo' => 'https://images.unsplash.com/photo-1590856029620-9c9a60c9b4a6',
                'hero_image' => 'https://images.unsplash.com/photo-1590856029620-9c9a60c9b4a6',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => false,
                'category_id' => 2,
            ],
            [
                'name' => 'PrimeFlow Experts',
                'logo' => 'https://images.unsplash.com/photo-1617104551722-3b2d0c66fc3f',
                'hero_image' => 'https://images.unsplash.com/photo-1617104551722-3b2d0c66fc3f',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => true,
                'category_id' => 1,
            ],
            [
                'name' => 'CityWide Plumbing',
                'logo' => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c',
                'hero_image' => 'https://images.unsplash.com/photo-1600585154340-be6161a56a0c',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => false,
                'category_id' => 2,
            ],
            [
                'name' => 'PipePro Mechanics',
                'logo' => 'https://images.unsplash.com/photo-1600573472591-ee6c34c8a5b5',
                'hero_image' => 'https://images.unsplash.com/photo-1600573472591-ee6c34c8a5b5',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => false,
                'category_id' => 2,
            ],
            [
                'name' => 'Neighborhood Plumbers',
                'logo' => 'https://images.unsplash.com/photo-1600566753086-00f18fb6b3ea',
                'hero_image' => 'https://images.unsplash.com/photo-1600566753086-00f18fb6b3ea',
                'lat' => 37.774929,
                'lon' => -122.419416,
                'location' => 'San Francisco, CA',
                'is_verified' => true,
                'category_id' => 1,
            ],
        ];

        // Insert or update each shop
        foreach ($shops as $index => $shop) {
            $shop['shop_code'] = 'SH' . str_pad($index + 1, 4, '0', STR_PAD_LEFT);
            DB::table('shops')->updateOrInsert(
                ['shop_code' => $shop['shop_code']],
                array_merge($shop, [
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ])
            );
        }
    }
}
