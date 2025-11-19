<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Lead;
use App\Models\User;

class LeadSeeder extends Seeder
{
    const STATUS_NEW = 'New';
    const STATUS_CONTACTED = 'Contacted';
    const STATUS_CONVERTED_TO_DEAL = 'Converted-to-Deal';
    const STATUS_INTERESTED = 'Interested';
    const STATUS_WON = 'Closed-Won';
    const STATUS_LOST = 'Closed-Lost';

    const SOURCE_FACEBOOK = 'Facebook';
    const SOURCE_INSTAGRAM = 'Instagram';
    const SOURCE_WEBSITE = 'Website';
    const SOURCE_WHATSAPP = 'WhatsApp';
    const SOURCE_REFERRAL = 'Referral';

    public function run(): void
    {
        Lead::truncate();

        // Fetch some users for customer_id and agent_id
        $customers = User::pluck('id')->toArray();
        $agents = User::pluck('id')->toArray();

        if (empty($customers)) {
            $this->command->info('No users found for seeding leads.');
            return;
        }

        $statuses = [
            self::STATUS_NEW,
            self::STATUS_CONTACTED,
            self::STATUS_CONVERTED_TO_DEAL,
            self::STATUS_INTERESTED,
            self::STATUS_WON,
            self::STATUS_LOST
        ];

        $sources = [
            self::SOURCE_FACEBOOK,
            self::SOURCE_INSTAGRAM,
            self::SOURCE_WEBSITE,
            self::SOURCE_WHATSAPP,
            self::SOURCE_REFERRAL
        ];

        for ($i = 0; $i < 100; $i++) {
            Lead::create([
                'customer_id' => $customers[array_rand($customers)],
                'agent_id' => $agents[array_rand($agents)],
                'source' => $sources[array_rand($sources)],
                'status' => $statuses[array_rand($statuses)],
            ]);
        }

        $this->command->info('Leads seeded successfully.');
    }
}
