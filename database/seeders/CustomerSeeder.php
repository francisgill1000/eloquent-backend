<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;
use Faker\Factory as Faker;
use Carbon\Carbon;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        // Customer::truncate();
        
        $faker = Faker::create();

        // Year for which you want to seed customers
        $year = now()->year;

        // Loop through each month of the year
        for ($month = 1; $month <= 12; $month++) {
            // Number of customers to create for this month
            $customersCount = rand(5, 15); // Random count for variety

            for ($i = 0; $i < $customersCount; $i++) {
                // Random day within the month
                $day = rand(1, Carbon::create($year, $month)->daysInMonth);

                // Random time in that day
                $createdAt = Carbon::create($year, $month, $day, rand(0, 23), rand(0, 59), rand(0, 59));

                Customer::create([
                    'user_id'  => 19,
                    'name'     => $faker->name,
                    'email'    => $faker->unique()->safeEmail,
                    'phone'    => $faker->phoneNumber,
                    'whatsapp' => $faker->phoneNumber,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                    'country'   => "AE",
                    "city"      =>  $this->getRandomCity()
                ]);
            }
        }
    }

    public function getRandomCity()
    {
        $cities = [
            "AE_AUH",
            "AE_DXB",
            "AE_SHJ",
            "AE_AJM",
            "AE_FUJ",
            "AE_RAK",
            "AE_UAQ",
        ];

        return $cities[array_rand($cities)];
    }
}
