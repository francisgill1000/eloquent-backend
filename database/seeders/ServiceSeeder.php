<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            ['id' => null,        'label' => 'All Services'],
            ['id' => 1, 'code' => '0001',    'label' => 'Barber'],
            ['id' => 2, 'code' => '0002',  'label' => 'Plumbing'],
            ['id' => 3, 'code' => '0003', 'label' => 'AC Repair'],
            ['id' => 4, 'code' => '0004', 'label' => 'Electrician'],
            ['id' => 5, 'code' => '0005',  'label' => 'Car Wash'],
            ['id' => 6, 'code' => '0006',  'label' => 'Painting'],
            ['id' => 7, 'code' => '0007',  'label' => 'Cleaning'],
            ['id' => 8, 'code' => '0008', 'label' => 'Pest Control'],
        ];

        foreach ($services as $service) {
            DB::table('services')->updateOrInsert(
                ['id' => $service['id']],
                [
                    'label' => $service['label'],
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]
            );
        }
    }
}
