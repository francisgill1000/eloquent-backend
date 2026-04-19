<?php

namespace App\Console\Commands;

use App\Ai\KnowledgeBase;
use App\Models\AiKbEntry;
use Illuminate\Console\Command;

class AssistantKbApprove extends Command
{
    protected $signature = 'assistant:kb-approve {kb_id : The kb_id of the entry to approve}';

    protected $description = 'Enable a KB entry (typically a suggestion) so it starts matching live traffic.';

    public function handle(): int
    {
        $kbId  = $this->argument('kb_id');
        $entry = AiKbEntry::where('kb_id', $kbId)->first();

        if (! $entry) {
            $this->error("No KB entry with kb_id={$kbId}");
            return self::FAILURE;
        }

        $entry->enabled = true;
        $entry->save();

        KnowledgeBase::flushCache();

        $this->info("Approved and enabled: {$kbId}");
        return self::SUCCESS;
    }
}
