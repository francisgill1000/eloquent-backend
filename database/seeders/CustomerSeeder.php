<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Customer;
use App\Models\User;
use Faker\Factory as Faker;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();

        // If you have users already, get their IDs; otherwise, set a static user_id
        // $userIds = User::pluck('id')->toArray();

        // If no users exist, you can optionally create one:
        // if (empty($userIds)) {
        //     $user = User::factory()->create();
        //     $userIds = [$user->id];
        // }

        for ($i = 0; $i < 100; $i++) {
            Customer::create([
                'user_id'  => 18,
                'name'     => $faker->name,
                'email'    => $faker->unique()->safeEmail,
                'phone'    => $faker->phoneNumber,
                'whatsapp' => $faker->phoneNumber,
            ]);
        }
    }
}
