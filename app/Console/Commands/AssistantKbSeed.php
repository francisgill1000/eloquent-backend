<?php

namespace App\Console\Commands;

use App\Ai\KnowledgeBase;
use Database\Seeders\AiKbEntriesSeeder;
use Illuminate\Console\Command;

class AssistantKbSeed extends Command
{
    protected $signature = 'assistant:kb-seed';

    protected $description = 'Seed or refresh the AI knowledge base from KnowledgeBase::seedEntries() and flush its cache.';

    public function handle(AiKbEntriesSeeder $seeder): int
    {
        $this->info('Seeding AI knowledge base entries...');

        $seeder->setContainer(app())->setCommand($this)->run();
        KnowledgeBase::flushCache();

        $this->info('Done. KB cache flushed.');
        return self::SUCCESS;
    }
}
