<?php

namespace App\Console\Commands;

use App\Ai\KnowledgeBase;
use App\Models\AiKbEntry;
use Illuminate\Console\Command;

class AssistantKbReject extends Command
{
    protected $signature = 'assistant:kb-reject
        {kb_id : The kb_id of the suggestion to reject}
        {--delete : Delete the row instead of just disabling it}';

    protected $description = 'Reject a KB suggestion — disables it by default, or deletes with --delete.';

    public function handle(): int
    {
        $kbId  = $this->argument('kb_id');
        $entry = AiKbEntry::where('kb_id', $kbId)->first();

        if (! $entry) {
            $this->error("No KB entry with kb_id={$kbId}");
            return self::FAILURE;
        }

        if ($this->option('delete')) {
            $entry->delete();
            $this->info("Deleted: {$kbId}");
        } else {
            $entry->enabled = false;
            $entry->save();
            $this->info("Disabled: {$kbId}");
        }

        KnowledgeBase::flushCache();
        return self::SUCCESS;
    }
}
