<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;

class DealSeeder extends Seeder
{
    // Define statuses
    const STATUSES = [
        'Open',
        'Negotiation',
        'Closed-Won',
        'Closed-Lost',
    ];

    public function run(): void
    {
        Deal::truncate();

        $leads = Lead::pluck('id')->toArray();
        $customers = User::pluck('id')->toArray();
        $agents = User::pluck('id')->toArray();

        if (empty($leads) || empty($customers) || empty($agents)) {
            $this->command->info('Not enough data for seeding deals.');
            return;
        }

        if (empty($customers)) {
            $this->command->info('No users found for seeding deals.');
            return;
        }

        for ($i = 0; $i < 100; $i++) {
            $amount = random_int(1000, 50000);
            $discount = random_int(0, 5000);
            $tax = random_int(0, 5000);
            $total = $amount - $discount + $tax;

            Deal::create([
                'lead_id' => $leads[array_rand($leads)],
                'customer_id' => $customers[array_rand($customers)],
                'agent_id' => $agents[array_rand($agents)],
                'deal_title' => 'Deal ' . ($i + 1),
                'amount' => $amount,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'status' => self::STATUSES[random_int(0, count(self::STATUSES) - 1)],
                'expected_close_date' => now()->addDays(random_int(1, 60)),
            ]);
        }

        $this->command->info('Deals seeded successfully.');
    }
}
