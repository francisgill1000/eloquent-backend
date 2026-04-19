<?php

namespace Database\Seeders;

use App\Ai\KnowledgeBase;
use App\Models\AiKbEntry;
use Illuminate\Database\Seeder;

class AiKbEntriesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (KnowledgeBase::seedEntries() as $entry) {
            AiKbEntry::updateOrCreate(
                ['kb_id' => $entry['id']],
                [
                    'patterns' => $entry['patterns'],
                    'answer'   => $entry['answer'],
                    'priority' => $entry['priority'] ?? 100,
                    'enabled'  => true,
                    'source'   => 'seeded',
                ]
            );
        }

        KnowledgeBase::flushCache();
    }
}
