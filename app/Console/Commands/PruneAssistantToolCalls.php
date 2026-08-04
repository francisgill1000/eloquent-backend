<?php

namespace App\Console\Commands;

use App\Models\AssistantToolCall;
use Illuminate\Console\Command;

/**
 * Retention for the assistant tool-call log. A row stores the tool's raw input,
 * which for create_customer / create_booking includes a customer's name and
 * phone number. The log exists to investigate a change the assistant claimed
 * but never made; a month is long enough to look into something reported late,
 * and past that it is personal data held without a reason.
 */
class PruneAssistantToolCalls extends Command
{
    protected $signature = 'assistant:prune-tool-calls {--days=30}';

    protected $description = 'Delete assistant tool-call log rows older than --days';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $deleted = AssistantToolCall::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Pruned {$deleted} assistant tool-call log row(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
